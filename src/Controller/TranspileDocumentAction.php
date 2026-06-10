<?php

namespace App\Controller;

use App\Entity\ConversionJob;
use Doctrine\ORM\EntityManagerInterface;
use Smalot\PdfParser\Parser as PdfParser;

// ─── THE NAMESPACE DECLARATION FIX ───
use thiagoalessio\TesseractOCR\TesseractOCR; 

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TranspileDocumentAction extends AbstractController
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/api/conversion_jobs/transpile_document', name: 'api_transpile_document', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $uploadedFile = $request->files->get('file');
        $targetFormat = $request->request->get('targetFormat', 'text');
        $originType = $request->request->get('originType', 'docx');
        
        if (!$uploadedFile) {
            return new JsonResponse(['error' => 'No operational file stream dropped within transmission boundaries.'], Response::HTTP_BAD_REQUEST);
        }

        $filePath = $uploadedFile->getRealPath();
        $extractedText = '';

        try {
            if ($originType === 'pdf') {
                $pdfParser = new PdfParser();
                $pdf = $pdfParser->parseFile($filePath);
                $extractedText = $pdf->getText();
            } 
            elseif (in_array($originType, ['png', 'jpeg', 'webp', 'jpg'])) {
                $ocrInstance = new TesseractOCR($filePath);
                $ocrInstance->executable('/usr/bin/tesseract');
                $extractedText = $ocrInstance->run();
            } 
            else {
                $zip = new \ZipArchive();
                if ($zip->open($filePath) === true) {
                    if (($xmlIndex = $zip->locateName('word/document.xml')) !== false) {
                        $xmlData = $zip->getFromIndex($xmlIndex);
                        $extractedText = strip_tags(str_replace('</w:p>', "\n", $xmlData));
                    }
                    $zip->close();
                } else {
                    throw new \Exception('Failed to decompose OpenXML container structures.');
                }
            }

            $extractedText = trim(preg_replace('/\n\s*\n/', "\n\n", $extractedText));

            if ($targetFormat === 'markdown') {
                $extractedText = "# OCR Extraction: " . pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME) . "\n\n" . $extractedText;
            } elseif ($targetFormat === 'html') {
                $extractedText = "<h1>OCR Extraction: " . htmlspecialchars(pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME)) . "</h1>\n<p>" . nl2br(htmlspecialchars($extractedText)) . "</p>";
            }

            $job = new ConversionJob();
            $job->setOriginType($originType);
            
            // ─── CRITICAL DATABASE FIX ───
            $job->setSourceFormat($originType); 
            $job->setTargetFormat($targetFormat);
            // ─────────────────────────────
            
            $job->setStatus('completed');
            $job->setConversionType($originType . '_to_' . $targetFormat);
            $job->setCreatedAt(new \DateTimeImmutable());
            
            $userUri = $request->request->get('user');
            if ($userUri && preg_match('/\/api\/users\/([^\/]+)/', $userUri, $matches)) {
                $userUuidString = $matches[1];
                $user = $this->entityManager->getReference('App\Entity\User', $userUuidString);
                if ($user) {
                    $job->setUser($user);
                }
            }

            $this->entityManager->persist($job);
            $this->entityManager->flush();

            return new JsonResponse([
                'extractedContent' => $extractedText,
                'jobId' => $job->getId()
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            // ─── THE OCR EMPTY TEXT INTERCEPTOR ───
            // If Tesseract throws its raw command line panic, we map it to a friendly message.
            if (str_contains($errorMessage, 'did not produce any output')) {
                $errorMessage = 'The format has no text.';
            }

            return new JsonResponse([
                'error' => 'OCR conversion processing engine failed.',
                'detail' => $errorMessage
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}