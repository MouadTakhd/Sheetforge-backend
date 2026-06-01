<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ConversionController extends AbstractController
{
    #[Route('/conversion', name: 'app_conversion')]
    public function index(): Response
    {
        return $this->render('conversion/index.html.twig', [
            'controller_name' => 'ConversionController',
        ]);
    }
     #[Route('/conversion/temperature', name: 'app_conversion_temperature')]
     public function test(): Response
     {
        $conversion= new CConversion();
        $conversion->getbyId(1);
        return  $conversion->getFileName();
     }
}
