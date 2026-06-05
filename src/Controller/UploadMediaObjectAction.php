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

        // 2. Extractor Layer: Fallback to global inputs if framework serialization flushed properties
        $jobId = $request->request->get('jobId') ?? $_POST['jobId'] ?? $_REQUEST['jobId'] ?? null;
        $role = $request->request->get('role') ?? $_POST['role'] ?? $_REQUEST['role'] ?? 'input';

        /** @var UploadedFile|null $uploadedFile */
        $uploadedFile = $request->files->get('file');

        // Reconstruct reference if fallback environment globals holds it
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
            throw new BadRequestException('Please select a valid spreadsheet file (.csv, .xlsx, .xls) to upload.');
        }

        if (!$jobId) {
            throw new BadRequestException('Unable to coordinate document storage: The associated conversion workspace identifier is missing.');
        }

        // 4. Resolve and Validate Associated Conversion Target Relation
        /** @var ConversionJob|null $job */
        $job = $this->em->getRepository(ConversionJob::class)->find($jobId);
        if (!$job || $job->getUser() !== $user) {
            throw new NotFoundHttpException('The requested conversion session has expired or does not belong to your account.');
        }

        // 5. Sanitize File Parameters and compute extensions maps safely
        $role = in_array($role, ['input', 'output']) ? $role : 'input';
        $originalFilename = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = transliterator_transliterate('Any-Latin; Latin-ASCII; [^A-Za-z0-9_] remove; Lower()', $originalFilename);
        
        $extension = $uploadedFile->guessExtension();
        if (!$extension || $extension === 'bin') {
            $extension = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_EXTENSION) ?? 'xlsx';
        }
        $uniqueId = uniqid();

        // ─── PRODUCTION READY S3 BUCKET ENV EXTRACTORS ───
        $bucketName = $_ENV['AWS_S3_BUCKET'] ?? 'conversion-bucket';
        $currentPeriod = (new \DateTimeImmutable())->format('Y-m');
        
        $s3TargetKeyPath = sprintf('user_%s/%s/job_%s/%s_%s_%s.%s', $user->getId(), $currentPeriod, $job->getId(), $role, $safeFilename, $uniqueId, $extension);

        $fileRealPath = $uploadedFile->getRealPath();
        $checksum = hash_file('sha256', $fileRealPath);
        $sizeBytes = $uploadedFile->getSize();

        // 6. Execute S3 Direct Stream Upload
        try {
            if (!$this->s3Client->doesBucketExistV2($bucketName)) {
                $this->s3Client->createBucket(['Bucket' => $bucketName]);
                $this->s3Client->waitUntil('BucketExists', ['Bucket' => $bucketName]);
            }

            $this->s3Client->putObject([
                'Bucket'      => $bucketName,
                'Key'         => $s3TargetKeyPath,
                'SourceFile'  => $fileRealPath,
                'ContentType' => $uploadedFile->getClientMimeType(),
                'ACL'         => 'private',
            ]);
        } catch (\Exception $e) {
            throw new BadRequestException('Cloud storage backup failed. Please verify your file is not corrupted and try again.');
        }

        // ─── PRODUCTION READY URL STORAGE MAP GENERATOR ───
        // Pulls from a custom public variable string parameter to prevent hardcoded localhost addresses in cloud spaces
        $s3PublicBaseUrl = rtrim($_ENV['AWS_S3_PUBLIC_ENDPOINT'] ?? 'http://localhost:9000', '/');
        $publicCloudResourceUrl = sprintf('%s/%s/%s', $s3PublicBaseUrl, $bucketName, $s3TargetKeyPath);

        // ─── WORKSPACE STATUS MANAGEMENT FIX ───
        $job->setStatus('completed');
        $this->em->persist($job);

        // 7. Persist Metadata Entry log models map records
        $mediaObject = new MediaObject();
        $mediaObject->setUser($user);
        $mediaObject->setJob($job);
        $mediaObject->setRole($role);
        $mediaObject->setFileName($uploadedFile->getClientOriginalName());
        $mediaObject->setFilePathUrl($publicCloudResourceUrl);
        $mediaObject->setMimeType($uploadedFile->getClientMimeType());
        $mediaObject->setSizeBytes((string)$sizeBytes);
        $mediaObject->setChecksum($checksum);
        $mediaObject->setExpiresAt((new \DateTimeImmutable())->modify('+48 hours'));

        $this->em->persist($mediaObject);

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
                // Fail silently on analytics constraints to protect upload speeds
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