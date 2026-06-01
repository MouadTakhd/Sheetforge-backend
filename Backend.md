# Excel Converter — Database & Storage Architecture

**Stack:** Symfony 7 · PostgreSQL 16 · S3 (MinIO local) · Redis · React + Vite + TanStack + Zustand

---

## Table of Contents

1. [Overview](#overview)
2. [Database Schema](#database-schema)
3. [Table Reference](#table-reference)
4. [Storage Strategy](#storage-strategy)
5. [Data Flow](#data-flow)
6. [Symfony Implementation](#symfony-implementation)
7. [S3 / MinIO Setup](#s3--minio-setup)
8. [Redis & Job Queue](#redis--job-queue)
9. [Environment Configuration](#environment-configuration)
10. [Indexes & Performance](#indexes--performance)
11. [Migrations Cheatsheet](#migrations-cheatsheet)

---

## Overview

The application uses three distinct storage systems, each with a clear responsibility:

| Layer | Technology | Stores |
|---|---|---|
| Relational DB | PostgreSQL | All structured metadata, job state, user records |
| Object storage | S3 / MinIO | Binary file bytes (uploads + converted outputs) |
| Queue / cache | Redis | Symfony Messenger job queue, optional status cache |

**Rule:** PostgreSQL never stores file bytes. S3 never stores structured data. The `files.storage_key` column is the bridge between them.

---

## Database Schema

### Entity Relationship Overview

```
users
 ├── refresh_tokens      (JWT sessions)
 ├── conversion_jobs     (one job per conversion request)
 │    └── files          (input + output files per job)
 ├── templates           (saved mapping configs)
 ├── usage_stats         (aggregated per user per period)
 └── audit_logs          (immutable action log)
```

---

## Table Reference

### `users`

Central identity table. Supports soft delete via `deleted_at`.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | Generated with `uuid_generate_v4()` |
| `email` | `varchar(180)` UNIQUE | Lowercase, trimmed before insert |
| `password_hash` | `varchar(255)` | bcrypt via Symfony `PasswordHasher` |
| `first_name` | `varchar(100)` | |
| `last_name` | `varchar(100)` | |
| `role` | `varchar(20)` | `ROLE_USER` \| `ROLE_ADMIN` |
| `status` | `varchar(20)` | `active` \| `pending` \| `suspended` |
| `locale` | `varchar(10)` | Default `en` |
| `timezone` | `varchar(50)` | Default `UTC` |
| `email_verified_at` | `timestamptz` | NULL = not verified |
| `last_login_at` | `timestamptz` | Updated on each successful login |
| `created_at` | `timestamptz` | Set on insert, never updated |
| `updated_at` | `timestamptz` | Auto-updated via Doctrine lifecycle |
| `deleted_at` | `timestamptz` | NULL = active; set = soft deleted |

---

### `refresh_tokens`

One row per active device/session. Revoked by setting `revoked_at`.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | |
| `user_id` | `uuid` FK → `users.id` | CASCADE DELETE |
| `token_hash` | `varchar(255)` UNIQUE | SHA-256 of the raw token |
| `device_info` | `varchar(255)` | User-agent string, truncated |
| `expires_at` | `timestamptz` | Typically `now() + 30 days` |
| `created_at` | `timestamptz` | |
| `revoked_at` | `timestamptz` | NULL = still valid |

---

### `conversion_jobs`

Core table. One row per conversion request. Status transitions:  
`pending` → `processing` → `done` | `failed`

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | |
| `user_id` | `uuid` FK → `users.id` | CASCADE DELETE |
| `template_id` | `uuid` FK → `templates.id` | NULLABLE — if a template was used |
| `status` | `varchar(20)` | `pending` \| `processing` \| `done` \| `failed` |
| `conversion_type` | `varchar(50)` | e.g. `xlsx_to_csv`, `csv_to_xlsx` |
| `source_format` | `varchar(10)` | `xlsx` \| `csv` \| `ods` \| `pdf` |
| `target_format` | `varchar(10)` | `xlsx` \| `csv` \| `json` \| `pdf` |
| `options` | `jsonb` | Delimiter, sheet name, encoding, etc. |
| `error_message` | `text` | NULL unless `status = failed` |
| `progress_pct` | `smallint` | 0–100, updated by worker |
| `started_at` | `timestamptz` | Set when worker picks up job |
| `finished_at` | `timestamptz` | Set when done or failed |
| `created_at` | `timestamptz` | |
| `updated_at` | `timestamptz` | |

**`options` JSON shape example:**
```json
{
  "delimiter": ";",
  "sheet_name": "Sheet1",
  "encoding": "UTF-8",
  "skip_empty_rows": true,
  "date_format": "d/m/Y"
}
```

---

### `files`

Stores metadata for every file — both uploaded inputs and converted outputs. Never stores bytes.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | |
| `user_id` | `uuid` FK → `users.id` | CASCADE DELETE |
| `job_id` | `uuid` FK → `conversion_jobs.id` | CASCADE DELETE |
| `role` | `varchar(10)` | `input` \| `output` |
| `original_name` | `varchar(255)` | Original filename from upload |
| `storage_key` | `varchar(500)` UNIQUE | Path in S3, e.g. `user_42/2026/06/abc.xlsx` |
| `mime_type` | `varchar(100)` | e.g. `application/vnd.openxmlformats-...` |
| `size_bytes` | `bigint` | File size in bytes |
| `checksum` | `varchar(64)` | SHA-256 hex of file content |
| `expires_at` | `timestamptz` | Matches S3 lifecycle policy (e.g. +48h) |
| `created_at` | `timestamptz` | |

---

### `templates`

Reusable column-mapping and transformation configs, owned by a user, optionally public.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | |
| `owner_id` | `uuid` FK → `users.id` | CASCADE DELETE |
| `name` | `varchar(150)` | |
| `description` | `text` | NULLABLE |
| `mapping_config` | `jsonb` | Column mappings, transformations |
| `is_public` | `boolean` | Default `false` |
| `created_at` | `timestamptz` | |
| `updated_at` | `timestamptz` | |

**`mapping_config` JSON shape example:**
```json
{
  "columns": [
    { "source": "Nom", "target": "last_name", "transform": "trim|uppercase" },
    { "source": "Date naissance", "target": "dob", "transform": "date:d/m/Y" }
  ],
  "skip_header_rows": 1
}
```

---

### `usage_stats`

Pre-aggregated per user per calendar period. Updated by a worker after each job completes. Avoids expensive COUNT queries on `conversion_jobs`.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | |
| `user_id` | `uuid` FK → `users.id` | CASCADE DELETE |
| `period` | `varchar(7)` | `YYYY-MM` e.g. `2026-06` |
| `jobs_total` | `integer` | |
| `jobs_ok` | `integer` | |
| `jobs_failed` | `integer` | |
| `bytes_processed` | `bigint` | Sum of input file sizes |
| `created_at` | `timestamptz` | |
| `updated_at` | `timestamptz` | |

Unique constraint on `(user_id, period)` — use `INSERT ... ON CONFLICT DO UPDATE`.

---

### `audit_logs`

Append-only. Never updated or deleted. Records who did what and the before/after state.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | |
| `user_id` | `uuid` FK → `users.id` | SET NULL on delete |
| `action` | `varchar(100)` | e.g. `user.login`, `job.created`, `file.deleted` |
| `entity_type` | `varchar(50)` | Table name, e.g. `conversion_jobs` |
| `entity_id` | `uuid` | NULLABLE |
| `before_state` | `jsonb` | NULLABLE |
| `after_state` | `jsonb` | NULLABLE |
| `ip_address` | `varchar(45)` | IPv4 or IPv6 |
| `user_agent` | `varchar(500)` | |
| `created_at` | `timestamptz` | |

---

## Storage Strategy

### The golden rule

```
PostgreSQL  →  metadata only  (keys, status, sizes, names)
S3 / disk   →  bytes only     (the actual files)
```

### S3 key structure

```
{user_id}/{YYYY}/{MM}/{uniqid}_{original_filename}

Examples:
  user_0192fbe4/2026/06/6659a1b2_report.xlsx      ← input
  user_0192fbe4/2026/06/6659a1b2_report.csv        ← output
```

### File lifecycle

```
Upload   → S3 write  + files row INSERT  (role = input,  expires_at = +48h)
Convert  → S3 write  + files row INSERT  (role = output, expires_at = +48h)
Expire   → Cron job deletes S3 object + files row (when expires_at < now())
```

### Download flow (pre-signed URLs)

The API never proxies file bytes. Instead:

1. Frontend calls `GET /api/files/{id}/download-url`
2. Symfony checks ownership, generates a 15-minute S3 pre-signed URL
3. Frontend redirects browser directly to S3

This keeps the Symfony server out of the download path entirely.

---

## Data Flow

```
[Browser]
   │
   │  POST /api/convert  (multipart: file + options)
   ▼
[Symfony Controller]
   ├─► S3: writeStream(file bytes)         → returns storage_key
   ├─► PostgreSQL: INSERT conversion_jobs  → status = pending
   ├─► PostgreSQL: INSERT files            → role = input, storage_key
   └─► Redis: dispatch(ConversionJobMessage(jobId))
   │
   │  202 Accepted + { jobId }
   ▼
[React — polls GET /api/jobs/{id}]
   │
   │  [Worker process — Symfony Messenger consumer]
   ├─► S3: readStream(input storage_key)
   ├─► PhpSpreadsheet: convert bytes
   ├─► S3: writeStream(output bytes)       → returns output storage_key
   ├─► PostgreSQL: INSERT files            → role = output, storage_key
   └─► PostgreSQL: UPDATE conversion_jobs  → status = done, progress_pct = 100
   │
   │  GET /api/files/{id}/download-url
   ▼
[Symfony] → S3 pre-signed URL (15 min TTL)
   │
   ▼
[Browser downloads directly from S3]
```

---

## Symfony Implementation

### Directory structure

```
src/
├── Entity/
│   ├── User.php
│   ├── RefreshToken.php
│   ├── ConversionJob.php
│   ├── File.php
│   ├── Template.php
│   ├── UsageStat.php
│   └── AuditLog.php
├── Enum/
│   ├── JobStatus.php
│   ├── UserRole.php
│   ├── UserStatus.php
│   └── FileRole.php
├── Message/
│   └── ConversionJobMessage.php
├── MessageHandler/
│   └── ConversionJobHandler.php
└── Service/
    ├── FileStorageService.php
    └── AuditService.php
```

### PHP Enums

```php
// src/Enum/JobStatus.php
enum JobStatus: string {
    case Pending    = 'pending';
    case Processing = 'processing';
    case Done       = 'done';
    case Failed     = 'failed';
}

// src/Enum/FileRole.php
enum FileRole: string {
    case Input  = 'input';
    case Output = 'output';
}

// src/Enum/UserStatus.php
enum UserStatus: string {
    case Active    = 'active';
    case Pending   = 'pending';
    case Suspended = 'suspended';
}
```

### Messenger message & handler

```php
// src/Message/ConversionJobMessage.php
final class ConversionJobMessage
{
    public function __construct(public readonly string $jobId) {}
}

// src/MessageHandler/ConversionJobHandler.php
#[AsMessageHandler]
final class ConversionJobHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConversionJobRepository $jobRepo,
        private FileStorageService $storage,
    ) {}

    public function __invoke(ConversionJobMessage $msg): void
    {
        $job = $this->jobRepo->find($msg->jobId);
        $job->setStatus(JobStatus::Processing);
        $job->setStartedAt(new \DateTimeImmutable());
        $this->em->flush();

        try {
            // 1. Stream input from S3
            $inputFile = $job->getInputFile();
            $stream = $this->storage->readStream($inputFile->getStorageKey());

            // 2. Convert with PhpSpreadsheet...
            $outputBytes = $this->convert($stream, $job->getOptions());

            // 3. Write output to S3
            $outputKey = $this->storage->storeBytes(
                $outputBytes,
                $job->getUser(),
                $job->getTargetFormat()
            );

            // 4. Persist output file row
            $outputFile = new File();
            $outputFile->setJob($job)
                       ->setUser($job->getUser())
                       ->setRole(FileRole::Output)
                       ->setStorageKey($outputKey)
                       ->setExpiresAt(new \DateTimeImmutable('+48 hours'));
            $this->em->persist($outputFile);

            $job->setStatus(JobStatus::Done)
                ->setProgressPct(100)
                ->setFinishedAt(new \DateTimeImmutable());

        } catch (\Throwable $e) {
            $job->setStatus(JobStatus::Failed)
                ->setErrorMessage($e->getMessage())
                ->setFinishedAt(new \DateTimeImmutable());
        }

        $this->em->flush();
    }
}
```

### FileStorageService

```php
// src/Service/FileStorageService.php
final class FileStorageService
{
    public function __construct(
        private FilesystemOperator $defaultStorage,  // injected by Flysystem bundle
        private S3Client $s3Client,
        private string $bucket,
    ) {}

    public function store(UploadedFile $upload, User $user): string
    {
        $key = $this->buildKey($user, $upload->getClientOriginalName());
        $stream = fopen($upload->getPathname(), 'r');
        $this->defaultStorage->writeStream($key, $stream);
        fclose($stream);
        return $key;
    }

    public function storeBytes(string $bytes, User $user, string $ext): string
    {
        $key = $this->buildKey($user, 'output.' . $ext);
        $this->defaultStorage->write($key, $bytes);
        return $key;
    }

    public function readStream(string $key): mixed
    {
        return $this->defaultStorage->readStream($key);
    }

    public function delete(string $key): void
    {
        $this->defaultStorage->delete($key);
    }

    public function getPresignedUrl(string $key, int $ttlSeconds = 900): string
    {
        $cmd = $this->s3Client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key'    => $key,
        ]);
        return (string) $this->s3Client
            ->createPresignedRequest($cmd, "+{$ttlSeconds} seconds")
            ->getUri();
    }

    private function buildKey(User $user, string $filename): string
    {
        return sprintf(
            'user_%s/%s/%s_%s',
            $user->getId(),
            (new \DateTime())->format('Y/m'),
            uniqid(),
            basename($filename)
        );
    }
}
```

---

## S3 / MinIO Setup

### Install dependencies

```bash
composer require league/flysystem-bundle league/flysystem-aws-s3-v3
```

### `config/packages/flysystem.yaml`

```yaml
flysystem:
    storages:
        default.storage:
            adapter: 'asyncaws.s3'
            options:
                bucket: '%env(AWS_S3_BUCKET)%'
                prefix: ~
```

### `config/services.yaml` (S3 client)

```yaml
services:
    Aws\S3\S3Client:
        arguments:
            - version: 'latest'
              region: '%env(AWS_S3_REGION)%'
              credentials:
                  key: '%env(AWS_ACCESS_KEY_ID)%'
                  secret: '%env(AWS_SECRET_ACCESS_KEY)%'
              # MinIO local override:
              endpoint: '%env(default::AWS_S3_ENDPOINT)%'
              use_path_style_endpoint: true

    App\Service\FileStorageService:
        arguments:
            $bucket: '%env(AWS_S3_BUCKET)%'
```

### MinIO via Docker (local dev)

```yaml
# docker-compose.yml
services:
  minio:
    image: minio/minio
    command: server /data --console-address ":9001"
    ports:
      - "9000:9000"   # S3 API
      - "9001:9001"   # Web console → http://localhost:9001
    environment:
      MINIO_ROOT_USER: minioadmin
      MINIO_ROOT_PASSWORD: minioadmin
    volumes:
      - minio_data:/data

volumes:
  minio_data:
```

### Bucket lifecycle policy (auto-expire files after 48h)

Apply via MinIO console or AWS CLI:

```json
{
  "Rules": [{
    "ID": "expire-conversions",
    "Status": "Enabled",
    "Filter": { "Prefix": "" },
    "Expiration": { "Days": 2 }
  }]
}
```

---

## Redis & Job Queue

### Install

```bash
composer require symfony/messenger symfony/redis-messenger
```

### `config/packages/messenger.yaml`

```yaml
framework:
    messenger:
        transports:
            async:
                dsn: '%env(REDIS_URL)%'
                options:
                    stream: conversion_jobs
                    group: workers
                    consumer: worker_%kernel.environment%
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 2

        routing:
            App\Message\ConversionJobMessage: async
```

### Start worker

```bash
php bin/console messenger:consume async --limit=10 --time-limit=3600 -vv
```

### Docker service for worker

```yaml
# docker-compose.yml
  worker:
    build: .
    command: php bin/console messenger:consume async --limit=50 --time-limit=3600
    environment:
      - APP_ENV=prod
    depends_on:
      - redis
      - postgres
    restart: unless-stopped
```

---

## Environment Configuration

### `.env`

```dotenv
# Database
DATABASE_URL="postgresql://app:secret@localhost:5432/excel_converter?serverVersion=16&charset=utf8"

# S3 Production
AWS_S3_BUCKET=excel-converter-prod
AWS_S3_REGION=eu-west-1
AWS_ACCESS_KEY_ID=your-key-id
AWS_SECRET_ACCESS_KEY=your-secret-key

# Redis
REDIS_URL=redis://localhost:6379
```

### `.env.local` (local dev — MinIO)

```dotenv
DATABASE_URL="postgresql://app:secret@localhost:5432/excel_converter_dev?serverVersion=16&charset=utf8"

AWS_S3_BUCKET=excel-converter
AWS_S3_REGION=us-east-1
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_S3_ENDPOINT=http://localhost:9000

REDIS_URL=redis://localhost:6379
```

---

## Indexes & Performance

```sql
-- users
CREATE UNIQUE INDEX idx_users_email       ON users (email);
CREATE INDEX         idx_users_status      ON users (status);
CREATE INDEX         idx_users_deleted_at  ON users (deleted_at) WHERE deleted_at IS NOT NULL;

-- refresh_tokens
CREATE UNIQUE INDEX idx_rt_token_hash     ON refresh_tokens (token_hash);
CREATE INDEX         idx_rt_user_id        ON refresh_tokens (user_id);
CREATE INDEX         idx_rt_expires_at     ON refresh_tokens (expires_at) WHERE revoked_at IS NULL;

-- conversion_jobs
CREATE INDEX idx_jobs_user_id    ON conversion_jobs (user_id);
CREATE INDEX idx_jobs_status     ON conversion_jobs (status);
CREATE INDEX idx_jobs_created_at ON conversion_jobs (created_at DESC);

-- files
CREATE UNIQUE INDEX idx_files_storage_key ON files (storage_key);
CREATE INDEX         idx_files_job_id      ON files (job_id);
CREATE INDEX         idx_files_expires_at  ON files (expires_at) WHERE expires_at IS NOT NULL;

-- usage_stats
CREATE UNIQUE INDEX idx_usage_user_period ON usage_stats (user_id, period);

-- audit_logs
CREATE INDEX idx_audit_user_id    ON audit_logs (user_id);
CREATE INDEX idx_audit_entity     ON audit_logs (entity_type, entity_id);
CREATE INDEX idx_audit_created_at ON audit_logs (created_at DESC);
```

---

## Migrations Cheatsheet

```bash
# Generate migration from entity changes
php bin/console make:migration

# Run pending migrations
php bin/console doctrine:migrations:migrate

# Check migration status
php bin/console doctrine:migrations:status

# Validate schema matches entities
php bin/console doctrine:schema:validate

# Fresh database (dev only)
php bin/console doctrine:database:drop --force
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate -n
```

---

*Last updated: June 2026 — v1.0*