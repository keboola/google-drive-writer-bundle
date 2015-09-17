<?php
/**
 * RestApi.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 24.6.13
 */

namespace Keboola\Google\DriveWriterBundle\GoogleDrive;

use GuzzleHttp\Message\Response;
use Keboola\Csv\CsvFile;
use Keboola\Google\ClientBundle\Google\RestApi as GoogleApi;
use Keboola\Google\DriveWriterBundle\Entity\File;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Templating\EngineInterface;

class RestApi
{
	/** @var GoogleApi */
	protected $api;

	/** @var EngineInterface */
	protected $templating;

	const FILE_UPLOAD = 'https://www.googleapis.com/upload/drive/v2/files';

	const FILE_METADATA = 'https://www.googleapis.com/drive/v2/files';

	const SPREADSHEET_CELL_BATCH = 'https://spreadsheets.google.com/feeds/cells/%s/%s/private/full/batch';

	const SPREADSHEET_CELL = 'https://spreadsheets.google.com/feeds/cells/%s/%s/private/full';

	const SPREADSHEET_WORKSHEETS = 'https://spreadsheets.google.com/feeds/worksheets';

	public function __construct(GoogleApi $api, EngineInterface $templating)
	{
		$this->api = $api;
		$this->templating = $templating;
	}

	public function getApi()
	{
		return $this->api;
	}

	public function listFiles($params = [])
	{
		return $this->api->call(self::FILE_METADATA, 'GET', [], $params)->json();
	}

	public function insertFile(File $file)
	{
		$convert = ($file->getType() == File::TYPE_SHEET)?'true':'false';

        $title = $file->isOperationCreate()?$file->getTitle() . ' (' . date('Y-m-d H:i:s') . ')':$file->getTitle();

        $body = [
            'title' => $title
        ];

        if (null != $file->getTargetFolder()) {
            $body['parents'][] = ['id' => $file->getTargetFolder()];
        }

		$response = $this->api->request(
			self::FILE_UPLOAD . '?uploadType=resumable&convert=' . $convert,
			'POST',
			[
				'Content-Type' => 'application/json; charset=UTF-8',
				'X-Upload-Content-Type' => 'text/csv',
				'X-Upload-Content-Length' => $file->getSize()
			],
			json_encode($body)
		);

		$locationUri = $response->getHeader('Location');

		return $this->putFile($file, $locationUri, $convert);
	}

	public function updateFile(File $file)
	{
		$response = $this->api->request(
			self::FILE_UPLOAD . '/' . $file->getGoogleId() . '?uploadType=resumable',
			'PUT',
			[
				'Content-Type' => 'application/json; charset=UTF-8',
				'X-Upload-Content-Type' => 'text/csv',
				'X-Upload-Content-Length' => $file->getSize()
			],
			json_encode([
				'title' => $file->getTitle()
			])
		);

		$locationUri = $response->getHeader('Location');

		return $this->putFile($file, $locationUri, false);
	}

	protected function putFile(File $file, $locationUri, $convert)
	{
		return $this->api->call($locationUri . '&convert=' . $convert, 'PUT', [
			'Content-Type' => 'text/csv',
			'Content-Length' => $file->getSize()
		], fopen($file->getPathname(), 'r'))->json();
	}

    public function getFile($id)
    {
        return $this->api->call(self::FILE_METADATA . '/' . $id, 'GET')->json();
    }

	public function deleteFile(File $file)
	{
		return $this->api->call(self::FILE_METADATA . '/' . $file->getGoogleId(), 'DELETE');
	}


	/** SHEETS */

	/**
	 * @param File $file
	 */
	public function listSheets($file)
	{

	}

	public function updateCells(File $file)
	{
        $csvFile = new CsvFile($file->getPathname());

        $limit = 5000;
        $offset = 0;
        $rowCnt = $this->countLines($csvFile);
        $colCnt = $csvFile->getColumnsCount();

        // decrease the limit according to $colCnt
        if ($colCnt > 20) {
            $limit = intval($limit / intval($colCnt / 20));
        }

        $errors = [];

        if ($rowCnt > $limit) {

            // request is decomposed to several smaller requests, response is thrown away
            for ($i=0; $i <= intval($rowCnt/$limit); $i++) {
                $response = $this->api->call(
                    sprintf(self::SPREADSHEET_CELL_BATCH, $file->getGoogleId(), $file->getSheetId()),
                    'POST',
                    [
                        'Accept' => 'application/atom+xml',
                        'Content-Type' => 'application/atom+xml',
                        'GData-Version' => '3.0',
                        'If-Match' => '*'
                    ],
                    $this->templating->render(
                        'KeboolaGoogleDriveWriterBundle:Feed:Cell/batch.xml.twig',
                        [
                            'csv' => $csvFile,
                            'fileId' => $file->getGoogleId(),
                            'worksheetId' => $file->getSheetId(),
                            'limit' => $limit,
                            'offset' => $offset
                        ]
                    )
                );

	            $errors = array_merge($errors, $this->validateResponse($response));

                $offset += $limit;
            }

        } else {

            $response = $this->api->call(
                sprintf(self::SPREADSHEET_CELL_BATCH, $file->getGoogleId(), $file->getSheetId()),
                'POST',
                [
                    'Accept' => 'application/atom+xml',
                    'Content-Type' => 'application/atom+xml',
                    'GData-Version' => '3.0',
                    'If-Match' => '*'
                ],
                $this->templating->render(
                    'KeboolaGoogleDriveWriterBundle:Feed:Cell/batch.xml.twig',
                    [
                        'csv' => $csvFile,
                        'fileId' => $file->getGoogleId(),
                        'worksheetId' => $file->getSheetId(),
                        'limit' => $limit,
                        'offset' => $offset
                    ]
                )
            );

	        $errors = $this->validateResponse($response);
        }

        return [
	        'errors' => $errors
        ];
	}

    public function createWorksheet(File $file)
    {
        $csvFile = new CsvFile($file->getPathname());
        $colCount = $csvFile->getColumnsCount();
        $rowCount = $this->countLines($csvFile);

        $entryXml = $this->templating->render(
            'KeboolaGoogleDriveWriterBundle:Feed:Worksheet/entry.xml.twig',
            [
                'title' => $file->getTitle(),
                'cols' => $colCount,
                'rows' => $rowCount
            ]
        );

        return $this->api->call(
            sprintf(self::SPREADSHEET_WORKSHEETS . '/%s/private/full', $file->getGoogleId()),
            'POST',
            [
                'Accept'        => 'application/atom+xml',
                'Content-Type'  => 'application/atom+xml',
                'GData-Version' => '3.0',
            ],
            $entryXml
        );
    }

	public function updateWorksheet(File $file)
	{
		$csvFile = new CsvFile($file->getPathname());
		$colCount = $csvFile->getColumnsCount();
		$rowCount = $this->countLines($csvFile);

		$worksheetsFeedXml = $this->getWorksheetsFeed($file->getGoogleId(), false);

		$crawler = new Crawler($worksheetsFeedXml);

		$entryIds = $crawler->filter('default|entry default|id');

		/** @var \DOMElement $eid */
		foreach ($entryIds as $eid) {
			if (strstr($eid->nodeValue, $file->getSheetId()) !== false) {
				$entry = $eid->parentNode;
				$entryXml = $entry->ownerDocument->saveXML($entry);
				break;
			}
		}

		// update colCount and rowCount
		$entryXml = preg_replace('/\<gs\:colCount\>.*\<\/gs\:colCount\>/', '<gs:colCount>'.$colCount.'</gs:colCount>', $entryXml);
		$entryXml = preg_replace('/\<gs\:rowCount\>.*\<\/gs\:rowCount\>/', '<gs:rowCount>'.$rowCount.'</gs:rowCount>', $entryXml);

//		$entryXml = $this->templating->render(
//			'KeboolaGoogleDriveWriterBundle:Feed:Worksheet:entry.xml.twig',
//			[
//				'csv' => new CsvFile($file->getPathname()),
//				'fileId' => $file->getGoogleId(),
//				'worksheetId' => $file->getSheetId()
//			]
//		);

		$entryXml = "<?xml version='1.0' encoding='UTF-8'?>" . PHP_EOL . preg_replace('/\<entry.*\>\<id\>/', "<entry xmlns='http://www.w3.org/2005/Atom' xmlns:gs='http://schemas.google.com/spreadsheets/2006'><id>", $entryXml);

		return $this->api->call(
			sprintf(self::SPREADSHEET_WORKSHEETS . '/%s/private/full/%s', $file->getGoogleId(), $file->getSheetId()),
			'PUT',
			[
				'Accept'        => 'application/atom+xml',
				'Content-Type'  => 'application/atom+xml',
				'GData-Version' => '3.0',
				'If-Match' => '*'
			],
			$entryXml
		);
	}

	public function getWorksheetsFeed($fileId, $json = true)
	{
		$response = $this->api->call(
			self::SPREADSHEET_WORKSHEETS . '/' . $fileId . '/private/full' . ($json?'?alt=json':''),
			'GET',
			[
				'Accept' => $json?'application/json':'application/atom+xml',
				'GData-Version' => '3.0'
			]
		);

		return $json?$response->json():$response->getBody()->getContents();
	}

	public function getWorksheets($fileId)
	{
		$response = $this->api->call(
			self::SPREADSHEET_WORKSHEETS . '/' . $fileId . '/private/full?alt=json' ,
			'GET',
			[
				'Accept' => 'application/json',
			    'GData-Version' => '3.0'
			]
		);

		$response = $response->json();

		$result = [];
		if (isset($response['feed']['entry'])) {
			foreach($response['feed']['entry'] as $entry) {
				$wsUri = explode('/', $entry['id']['$t']);
				$wsId = array_pop($wsUri);
				$gid = $this->getGid($entry['link']);

				$result[$gid] = array(
					'id'    => $gid,
					'wsid'  => $wsId,
					'title' => $entry['title']['$t']
				);
			}

			return $result;
		}

		return false;
	}

	protected function getGid($links)
	{
		foreach ($links as $link) {
			if ($link['type'] == 'text/csv') {
				$linkArr = explode('?', $link['href']);
				$paramArr = explode('&', $linkArr[1]);

				return str_replace('gid=', '', $paramArr[0]);
			}
		}

		return null;
	}

	public function getCellsFeed(File $file)
	{
		$response = $this->api->call(
			sprintf(self::SPREADSHEET_CELL, $file->getGoogleId(), $file->getSheetId()) . '?alt=json',
			'GET',
			[
				'Accept' => 'application/json',
				'Content-Type' => 'application/atom+xml',
				'GData-Version' => '3.0'
			]
		);

		return $response->json();
	}

	protected function countLines(CsvFile $csvFile)
	{
		$cnt = 0;
		foreach ($csvFile as $row) {
			$cnt++;
		}
        $csvFile->rewind();
		return $cnt;
	}

	protected function validateResponse(Response $res)
	{
		/** @var Response $res */
		$batchStatuses = $this->parseXmlResponse($res->getBody()->getContents());

		$errors = [];
		foreach ($batchStatuses as $bs) {
			if (!isset($bs['reason']) || $bs['reason'] != 'Success') {
				$errors[] = $bs;
			}
		}

		return $errors;
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
