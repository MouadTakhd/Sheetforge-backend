<?php

namespace App\Controller;

use App\Entity\MediaObject;
use App\Entity\User;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class UploadMediaObjectAction extends AbstractController
{
    public function __construct(
        private S3Client $s3Client, // Native client injected directly
        private EntityManagerInterface $em
    ) {}

    public function __invoke(Request $request, #[CurrentUser] ?User $user): MediaObject
    {
        if (!$user) {
            throw $this->createAccessDeniedException('Identity parameter validation failure.');
        }

        /** @var UploadedFile|null $uploadedFile */
        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile) {
            throw new BadRequestException('"file" multipart parameter payload is missing.');
        }

        // 1. Sanitize file naming rules safely
        $originalFilename = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = transliterator_transliterate('Any-Latin; Latin-ASCII; [^A-Za-z0-9_] remove; Lower()', $originalFilename);
        $newFilename = $safeFilename.'-'.uniqid().'.' . $uploadedFile->guessExtension();

        // 2. Map isolated structural pathing: users/{user_uuid}/avatars/{filename}
        $s3TargetKeyPath = sprintf('users/%s/avatars/%s', $user->getId(), $newFilename);
        $bucketName = $_ENV['AWS_S3_BUCKET'] ?? 'sheetforge-assets';

        try {
            // 3. Put object straight to your S3 bucket natively using file stream pointers
            $this->s3Client->putObject([
                'Bucket'      => $bucketName,
                'Key'         => $s3TargetKeyPath,
                'SourceFile'  => $uploadedFile->getPathname(),
                'ContentType' => $uploadedFile->getClientMimeType(),
                'ACL'         => 'public-read', // Ensures link visibility for frontends
            ]);
        } catch (\Exception $e) {
            throw new BadRequestException('S3 Cloud upload pipeline drop out: ' . $e->getMessage());
        }

        // 4. Formulate the permanent public target link url matching your endpoint setup
        // Uses your internal or external S3 endpoint depending on environment configurations
        $publicCloudResourceUrl = sprintf('http://localhost:9000/%s/%s', $bucketName, $s3TargetKeyPath);

        // 5. Persist metadata tracing log row inside your database
        $mediaObject = new MediaObject();
        $mediaObject->setUser($user);
        $mediaObject->setFileName($uploadedFile->getClientOriginalName());
        $mediaObject->setFilePathUrl($publicCloudResourceUrl);
        $mediaObject->setMimeType($uploadedFile->getClientMimeType());

        $this->em->persist($mediaObject);
        $this->em->flush();

        return $mediaObject;
    }
}