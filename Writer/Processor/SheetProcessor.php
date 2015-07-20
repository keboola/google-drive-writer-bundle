<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 26/02/15
 * Time: 14:59
 */

namespace Keboola\Google\DriveWriterBundle\Writer\Processor;

use GuzzleHttp\Message\Response;
use GuzzleHttp\Exception\RequestException;
use Keboola\Google\DriveWriterBundle\Entity\File;
use Keboola\Syrup\Exception\UserException;
use Symfony\Component\DomCrawler\Crawler;

class SheetProcessor extends CommonProcessor
{
    public function process(File $file)
    {
        if (null == $file->getGoogleId() || $file->isOperationCreate()) {

            // create new file
            $fileRes = $this->googleDriveApi->insertFile($file);

            // get list of worksheets in file, there shall be only one
            $sheets = $this->googleDriveApi->getWorksheets($fileRes['id']);
            $sheet = array_shift($sheets);

            // update file
            $file->setGoogleId($fileRes['id']);
            $file->setSheetId($sheet['wsid']);

            $this->logger->info("Sheet created", [
                'file' => $file->toArray()
            ]);

        } else if ($file->isOperationUpdate()) {

            if (null == $file->getSheetId()) {
                // create new sheet in existing file
                $response = $this->googleDriveApi->createWorksheet($file);

                $crawler = new Crawler($response->getBody()->getContents());
                $uriArr = explode('/', $crawler->filter('default|id')->text());
                $file->setSheetId(array_pop($uriArr));

                $this->logger->info("Sheet created in existing file", [
                    'file' => $file->toArray()
                ]);
            } else {
                // update content of existing file

                try {
                    // update metadata first - cols and rows count
                    $this->googleDriveApi->updateWorksheet($file);

                    $this->logger->debug("Worksheet metadata updated", [
                        'file' => $file->toArray()
                    ]);

                    // update cells content
                    $timestart = microtime(true);
                    $responses = $this->googleDriveApi->updateCells($file);
                    $timeend = microtime(true);
                    $apiCallDuration = $timeend - $timestart;

                    // log responses for debug
                    $timestart = microtime(true);
                    foreach ($responses as $res) {

                        /** @var Response  $res */
                        $batchStatuses = $this->parseXmlResponse($res->getBody()->getContents());

                        $isWarning = false;
                        foreach ($batchStatuses as $bs) {
                            if (!isset($resBody['reason']) || $resBody['reason'] != 'Success') {
                                $isWarning = true;
                            }
                        }

                        if ($isWarning) {
                            $this->logger->warning("Warning: Some cells might not be imported properly", [
                                'response' => $batchStatuses
                            ]);
                        }
                    }
                    $timeend = microtime(true);
                    $responseParsingDuration = $timeend - $timestart;

                    $this->logger->debug("Worksheet cells updated", [
                        'file' => $file->toArray(),
                        'apiCallDuration' => $apiCallDuration,
                        'responseParsingDuration' => $responseParsingDuration
                    ]);

                } catch (RequestException $e) {
                    throw new UserException("Update failed: " . $e->getMessage(), $e, [
                        'file' => $file->toArray()
                    ]);
                }

                $this->logger->info("Sheet updated", [
                    'file' => $file->toArray()
                ]);
            }
        } else {

            // @TODO: append sheet
        }

        return $file;
    }

    protected function parseXmlResponse($xmlFeed)
    {
        $response = [];

        $xml = new \SimpleXMLElement($xmlFeed);
        foreach($xml->xpath('//batch:status') as $batchStatus) {
            $response[] = [
                'reason' => (string) $batchStatus['reason']
            ];
        }

        return $response;
    }
}
