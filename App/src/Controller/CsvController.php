<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\Request;
use App\Form\CsvUploadType;
use App\Entity\Product;

class CsvController extends AbstractController
{
    #[Route('/csv', name: 'app_csv')]
    public function index(): Response
    {
        return $this->render('csv/index.html.twig', [
            'controller_name' => 'CsvController',
        ]);
    }

    #[Route('/import', name: 'import_csv')]
    public function importCsv(Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $form = $this->createForm(CsvUploadType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $csvFile = $form->get('csv_file')->getData();
            if ($csvFile) {
                $originalFilename = pathinfo($csvFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$csvFile->guessExtension();

                try {
                    $csvFile->move(
                        $this->getParameter('kernel.project_dir') . '/public/uploads/csv',
                        $newFilename
                    );
                } catch (FileException $e) {
                    $this->addFlash('error', 'Could not upload file.');
                    return $this->redirectToRoute('import_csv');
                }

                // Read and process CSV file
                if (($handle = fopen($this->getParameter('kernel.project_dir') . '/public/uploads/csv/' . $newFilename, 'r')) !== false) {
                    // Skip the header
                    fgetcsv($handle);
                    while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                        // Assuming columns are firstName, lastName, email
                        $pro = new Product();
                        $pro->setName($data[0]);
                        $pro->setDescription($data[1]);
                        $pro->setPrice($data[2]);
                        $pro->setQuantity($data[3]);
                        $pro->setCreated(new \DateTime());

                        $em->persist($pro);
                    }

                    $em->flush();
                    fclose($handle);

                    $this->addFlash('success', 'CSV imported successfully!');
                    return $this->redirectToRoute('import_csv');
                }
            }
        } else {
            // dd('here else');
        }

        return $this->render('csv/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/export', name: 'export_csv')]
    public function exportCsv(EntityManagerInterface $em)
    {

        try {
            
            // Get the total number of products
            $totalProducts = $em->getRepository(Product::class)->count([]);
            $batchSize = 10;

            // Loop through the products in batches
            for ($offset = 0; $offset < $totalProducts; $offset += $batchSize) {
                // Fetch products in batches of 10
                $products = $em->getRepository(Product::class)->findBy([], null, $batchSize, $offset);

                // Create a unique filename for each batch
                $filePath = 'products_batch_' . ($offset / $batchSize + 1) . '.csv';

                // Open a file for writing the current batch
                $file = fopen($filePath, 'w');

                // Add CSV headers
                fputcsv($file, ['ID', 'Name', 'Price', 'Description']); // Adjust headers according to your Product fields

                // Process and export the batch of products
                foreach ($products as $product) {
                    // Write product details to the CSV file
                    fputcsv($file, [
                        $product->getId(),
                        $product->getName(),
                        $product->getPrice(),
                        $product->getDescription(),
                    ]);
                }

                // Close the file after writing the batch
                fclose($file);

                // Optional: Print processing message
                echo "Exported batch starting from offset {$offset} to {$filePath}.\n\n";
            }

            return new Response('exported');

        } catch (\Exception $e) {
            
        
        }
    }

    private function generateCsvContent($products): string
    {
        $handle = fopen('php://memory', 'r+');

        // Add header row
        fputcsv($handle, ['Name', 'Description', 'Price', 'Quantity']);

        // Add data rows
        foreach ($products as $product) {
            fputcsv($handle, [
                $product->getName(),
                $product->getDescription(),
                $product->getQuantity(),
                $product->getPrice(),
                $product->getCreated()->format('Y-m-d H:i:s')
            ]);
        }

        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        return $csvContent;
    }
}
