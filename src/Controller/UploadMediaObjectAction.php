<?php

namespace App\Controller;

use App\Entity\ConversionJob;
use App\Entity\MediaObject;
use App\Entity\User;
use App\Repository\UsageStatsRepository;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;

#[AsController]
class UploadMediaObjectAction extends AbstractController
{
    public function __construct(
        private S3Client $s3Client,
        private EntityManagerInterface $em,
        private UsageStatsRepository $usageStatsRepo,
        private SerializerInterface $serializer
    ) {}

    public function __invoke(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        // 1. Enforce Authentication Security Boundaries
        if (!$user) {
            throw new AccessDeniedHttpException('Authentication expired. Please log in again to continue your conversion.');
        }

        // 2. Extractor Layer
        $jobIdRaw = $request->request->get('jobId') ?? $_POST['jobId'] ?? $_REQUEST['jobId'] ?? null;
        
        // Extract raw UUID if the frontend transmits an API Platform IRI (e.g., "/api/conversion_jobs/uuid")
        $jobId = $jobIdRaw;
        if ($jobId && str_contains((string)$jobId, '/')) {
            $parts = explode('/', (string)$jobId);
            $jobId = end($parts);
        }

        $role = $request->request->get('role') ?? $_POST['role'] ?? $_REQUEST['role'] ?? 'input';
        $role = in_array($role, ['input', 'output', 'avatar']) ? $role : 'input';

        /** @var UploadedFile|null $uploadedFile */
        $uploadedFile = $request->files->get('file');

        if (!$uploadedFile && isset($_FILES['file'])) {
            $rawFile = $_FILES['file'];
            if (is_array($rawFile) && isset($rawFile['tmp_name']) && $rawFile['error'] === UPLOAD_ERR_OK) {
                $uploadedFile = new UploadedFile(
                    $rawFile['tmp_name'],
                    $rawFile['name'],
                    $rawFile['type'], 
                    $rawFile['error'],
                    true
                );
            }
        }

        // 3. User-Friendly Parameter Validations
        if (!$uploadedFile) {
            throw new BadRequestException('Please select a valid file payload asset to transmit.');
        }

        $job = null;
        if ($role !== 'avatar') {
            if (!$jobId) {
                throw new BadRequestException('Unable to coordinate document storage: The associated conversion workspace identifier is missing.');
            }

            /** @var ConversionJob|null $job */
            $job = $this->em->getRepository(ConversionJob::class)->find($jobId);
            
            if (!$job) {
                throw new NotFoundHttpException(sprintf('Database Error: Conversion job with ID "%s" could not be found.', $jobId));
            }

            if (!$job->getUser()) {
                throw new AccessDeniedHttpException('State Error: The requested conversion session exists but has no User attached to it in the database.');
            }

            // Strict UUID String Cast evaluation to safely bypass Doctrine Proxy wrappers
            $jobUserId = (string)$job->getUser()->getId();
            $currentUserId = (string)$user->getId();

            if ($jobUserId !== $currentUserId) {
                throw new AccessDeniedHttpException(sprintf('Security Violation: Job belongs to user %s, but token belongs to %s.', $jobUserId, $currentUserId));
            }
        }

        // 4. Sanitize File Parameters
        $originalFilename = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = transliterator_transliterate('Any-Latin; Latin-ASCII; [^A-Za-z0-9_] remove; Lower()', $originalFilename);
        
        $extension = $uploadedFile->guessExtension();
        if (!$extension || $extension === 'bin') {
            $extension = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_EXTENSION) ?? 'xlsx';
        }
        $uniqueId = uniqid();

        // S3 BUCKET PARAMS SETUP
        $bucketName = $_ENV['AWS_S3_BUCKET'] ?? 'conversion-bucket';
        $currentPeriod = (new \DateTimeImmutable())->format('Y-m');
        
        if ($role === 'avatar') {
            $s3TargetKeyPath = sprintf('user_%s/avatars/profile_%s.%s', $user->getId(), $uniqueId, $extension);
        } else {
            $s3TargetKeyPath = sprintf('user_%s/%s/job_%s/%s_%s_%s.%s', $user->getId(), $currentPeriod, $job->getId(), $role, $safeFilename, $uniqueId, $extension);
        }

        $fileRealPath = $uploadedFile->getRealPath();
        $checksum = hash_file('sha256', $fileRealPath);
        $sizeBytes = $uploadedFile->getSize();

        // 5. Execute S3 Direct Stream Upload
        try {
            if (!$this->s3Client->doesBucketExistV2($bucketName)) {
                $this->s3Client->createBucket(['Bucket' => $bucketName]);
                $this->s3Client->waitUntil('BucketExists', ['Bucket' => $bucketName]);
            }

            // ─── UNIFIED S3 BUCKET POLICY INJECTION ───
            // Forces the S3/MinIO bucket node layout parameters to allow anonymous reads specifically for profile pictures
            $bucketPolicyPayload = '{
                "Version": "2012-10-17",
                "Statement": [
                    {
                        "Sid": "AllowPublicAvatarReads",
                        "Effect": "Allow",
                        "Principal": "*",
                        "Action": ["s3:GetObject"],
                        "Resource": ["arn:aws:s3:::' . $bucketName . '/user_*/avatars/*"]
                    }
                ]
            }';

            $this->s3Client->putBucketPolicy([
                'Bucket' => $bucketName,
                'Policy' => $bucketPolicyPayload
            ]);

            // Stream upload payload block map assignment
            $this->s3Client->putObject([
                'Bucket'      => $bucketName,
                'Key'         => $s3TargetKeyPath,
                'SourceFile'  => $fileRealPath,
                'ContentType' => $uploadedFile->getClientMimeType(),
                'ACL'         => $role === 'avatar' ? 'public-read' : 'private'
            ]);

        } catch (\Exception $e) {
            throw new BadRequestException('Cloud storage backup failed. Please verify your file is not corrupted and try again. Detail: ' . $e->getMessage());
        }

        // PRODUCTION READY URL STORAGE MAP GENERATOR
        $s3PublicBaseUrl = rtrim($_ENV['AWS_S3_PUBLIC_ENDPOINT'] ?? 'http://localhost:9000', '/');
        $publicCloudResourceUrl = sprintf('%s/%s/%s', $s3PublicBaseUrl, $bucketName, $s3TargetKeyPath);

        // 6. Manage parent workspace state if applicable
        if ($job !== null) {
            $job->setStatus('completed');
            $this->em->persist($job);
        }

        // 7. Persist Metadata Entry log records
        $mediaObject = new MediaObject();
        $mediaObject->setUser($user);
        $mediaObject->setJob($job); 
        $mediaObject->setRole($role);
        $mediaObject->setFileName($uploadedFile->getClientOriginalName());
        $mediaObject->setFilePathUrl($publicCloudResourceUrl);
        $mediaObject->setMimeType($uploadedFile->getClientMimeType());
        $mediaObject->setSizeBytes((string)$sizeBytes);
        $mediaObject->setChecksum($checksum);
        $mediaObject->setExpiresAt($role === 'avatar' ? null : (new \DateTimeImmutable())->modify('+48 hours'));

        $this->em->persist($mediaObject);

        if ($role === 'avatar') {
            $user->setProfilePicture($publicCloudResourceUrl);
            $this->em->persist($user);
        }

        // 8. Increment User Analytics Counts
        if ($role === 'input') {
            try {
                $this->usageStatsRepo->incrementStats(
                    $user->getId(),
                    $currentPeriod,
                    true,
                    $sizeBytes
                );
            } catch (\Exception $e) {
                // Fail silently on matrix analytics constraints
            }
        }

        $this->em->flush();

        // 9. Serialize Using Coherent HTTP Media Groups Context
        $jsonPayloadString = $this->serializer->serialize($mediaObject, 'json', [
            'groups' => ['media:read']
        ]);

        return new JsonResponse($jsonPayloadString, 201, [], true);
    }
}