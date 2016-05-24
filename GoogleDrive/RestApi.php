<?php
/**
 * RestApi.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 24.6.13
 */

namespace Keboola\Google\DriveWriterBundle\GoogleDrive;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Response;
use Keboola\Csv\CsvFile;
use Keboola\Google\ClientBundle\Google\RestApi as GoogleApi;
use Keboola\Google\DriveWriterBundle\Entity\File;
use Keboola\Syrup\Exception\ApplicationException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Templating\EngineInterface;

class RestApi
{
	/** @var GoogleApi */
	protected $api;

	/** @var EngineInterface */
	protected $templating;

	const FILE_UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

	const FILE_METADATA = 'https://www.googleapis.com/drive/v3/files';

	const SPREADSHEET_CELL_BATCH = 'https://spreadsheets.google.com/feeds/cells/%s/%s/private/full/batch';

	const SPREADSHEET_CELL = 'https://spreadsheets.google.com/feeds/cells/%s/%s/private/full';

	const SPREADSHEET_WORKSHEETS = 'https://spreadsheets.google.com/feeds/worksheets';

	public function __construct(GoogleApi $api, EngineInterface $templating)
	{
		$this->api = $api;
		$this->api->setBackoffsCount(10);
		$this->templating = $templating;
	}

	public function getApi()
	{
		return $this->api;
	}

	public function listFiles($params = [])
	{
		$response = $this->api->request(self::FILE_METADATA, 'GET', [], ['query' => $params]);
		return json_decode($response->getBody(), true);
	}

	public function insertFile(File $file)
	{
		return $this->insertSimple($file);
	}

	private function insertSimple(File $file)
	{
		$title = $file->isOperationCreate()?$file->getTitle() . ' (' . date('Y-m-d H:i:s') . ')':$file->getTitle();

		$metadataUrl = sprintf('%s', self::FILE_METADATA);

		$body = [
			'name' => $title
		];

		if ($file->getType() == File::TYPE_SHEET) {
			$body['mimeType'] = 'application/vnd.google-apps.spreadsheet';
		}

		$response = $this->api->request(
			$metadataUrl,
			'POST',
			[
				'Content-Type' => 'application/json',
			],
			[
				'json' => $body
			]
		);

		$responseJson = json_decode($response->getBody(), true);

		$mediaUrl = sprintf('%s/%s?uploadType=media', self::FILE_UPLOAD, $responseJson['id']);

		$this->api->request(
			$mediaUrl,
			'PATCH',
			[
				'Content-Type' => 'text/csv',
				'Content-Length' => $file->getSize()
			],
			[
				'body' => \GuzzleHttp\Psr7\stream_for(fopen($file->getPathname(), 'r'))
			]
		);

		//move file to the right folder
		$res = $this->getFile($responseJson['id']);
		$metadataUrl = sprintf('%s/%s', self::FILE_METADATA, $res['id']);

		if ($file->getTargetFolder()) {
			$metadataUrl .= '?addParents=' . $file->getTargetFolder();

			if (!empty($res['parents'])) {
				$removeParents = implode(',', $res['parents']);
				$metadataUrl .= '&removeParents=' . $removeParents;
			}
		}

		$response = $this->api->request(
			$metadataUrl,
			'PATCH',
			[
				'Content-Type' => 'application/json',
			],
			[
				'json' => $body
			]
		);

		return json_decode($response->getBody(), true);
	}

	private function insertResumable(File $file)
	{
		$convert = ($file->getType() == File::TYPE_SHEET)?'true':'false';
		$title = $file->isOperationCreate()?$file->getTitle() . ' (' . date('Y-m-d H:i:s') . ')':$file->getTitle();

		$url = sprintf('%s?uploadType=resumable', self::FILE_UPLOAD);

		$body = [
			'name' => $title
		];

		if ($convert) {
			$body['mimeType'] = 'application/vnd.google-apps.spreadsheet';
		}

		if ($file->getTargetFolder()) {
			$url .= '&addParents=' . $file->getTargetFolder();
		}

		$response = $this->api->request(
			$url,
			'POST',
			[
				'Content-Type' => 'application/json; charset=UTF-8',
				'Content-Length' => mb_strlen(serialize($body), '8bit'),
				'X-Upload-Content-Type' => 'text/csv',
				'X-Upload-Content-Length' => $file->getSize()
			],
			[
				'json' => $body
			]
		);

		$locationUri = $response->getHeaderLine('Location');

		return $this->putFile($file, $locationUri);
	}

	public function updateFile(File $file)
	{
		$res = $this->getFile($file->getGoogleId());

		$url = sprintf('%s/%s?uploadType=resumable', self::FILE_UPLOAD, $file->getGoogleId());
		$body = ['name' => $file->getTitle()];

		if ($file->getTargetFolder()) {
			$url .= '&addParents=' . $file->getTargetFolder();
			if (!empty($res['parents'])) {
				$removeParents = implode(',', $res['parents']);
				$url .= '&removeParents=' . $removeParents;
			}
		}

		$response = $this->api->request(
			$url,
			'PATCH',
			[
				'Content-Type' => 'application/json; charset=UTF-8',
				'X-Upload-Content-Type' => 'text/csv',
				'X-Upload-Content-Length' => $file->getSize()
			],
			[
				'json' => $body
			]
		);

		$locationUri = $response->getHeaderLine('Location');

		return $this->putFile($file, $locationUri);
	}

	protected function putFile(File $file, $locationUri)
	{
		try {
			$response = $this->api->request(
				$locationUri,
				'PUT',
				[
					'Content-Type' => 'text/csv',
					'Content-Length' => $file->getSize()
				],
				[
					'body' => \GuzzleHttp\Psr7\stream_for(fopen($file->getPathname(), 'r'))
				]
			);
		} catch (BadResponseException $e) {
			$response = $e->getResponse();
			if ($response->getStatusCode() >= 300) {
				// get upload status
				$response = $this->api->request(
					$locationUri,
					'PUT',
					[
						'Content-Type' => 'text/csv',
						'Content-Length' => 0,
						'Content-Range' => 'bytes */*'
					]
				);

				$i = 0;
				$maxTries = 7;
				while ($response->getStatusCode() == 308 && $i < $maxTries) {
					$range = explode('-', $response->getHeaderLine('Range'));
					$remainingSize = $file->getSize() - $range[1]+1;

					// ffwd to byte where we left of
					$fh = fopen($file->getPathname(), 'r');
					fseek($fh, $range[1]+1);

					$response = $this->api->request(
						$locationUri,
						'PUT',
						[
							'Content-Length' => $remainingSize,
							'Content-Range' => sprintf('bytes %s/%s', $range[1]+1, $file->getSize())
						],
						[
							'body' => \GuzzleHttp\Psr7\stream_for($fh)
						]
					);

					sleep(pow(2, $i));
					$i++;
				}
			}
		}

		if ($response->getStatusCode() >= 300) {
			throw new ApplicationException("Error on PUT file", null, [
				'statusCode' => $response->getStatusCode(),
				'reason' => $response->getReasonPhrase(),
				'responseBody' => $response->getBody()
			]);
		}

		return json_decode($response->getBody(), true);
	}

    public function getFile($id)
    {
		$response = $this->api->request(
			sprintf('%s/%s?fields=%s', self::FILE_METADATA, $id, urlencode('id,name,mimeType,parents,trashed,webViewLink,originalFilename')),
			'GET'
		);
        return json_decode($response->getBody(), true);
    }

	public function deleteFile(File $file)
	{
		return $this->api->request(self::FILE_METADATA . '/' . $file->getGoogleId(), 'DELETE');
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

        $limit = 500;
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
                $response = $this->api->request(
                    sprintf(self::SPREADSHEET_CELL_BATCH, $file->getGoogleId(), $file->getSheetId()),
                    'POST',
                    [
                        'Accept' => 'application/atom+xml',
                        'Content-Type' => 'application/atom+xml',
                        'GData-Version' => '3.0',
                        'If-Match' => '*'
                    ],
                    [
						'body' => $this->templating->render(
							'KeboolaGoogleDriveWriterBundle:Feed:Cell/batch.xml.twig',
							[
								'csv' => $csvFile,
								'fileId' => $file->getGoogleId(),
								'worksheetId' => $file->getSheetId(),
								'limit' => $limit,
								'offset' => $offset
							]
						)
					]
                );

	            $errors = array_merge($errors, $this->validateResponse($response));

                $offset += $limit;
            }

        } else {

            $response = $this->api->request(
                sprintf(self::SPREADSHEET_CELL_BATCH, $file->getGoogleId(), $file->getSheetId()),
                'POST',
                [
                    'Accept' => 'application/atom+xml',
                    'Content-Type' => 'application/atom+xml',
                    'GData-Version' => '3.0',
                    'If-Match' => '*'
                ],
                [
					'body' => $this->templating->render(
						'KeboolaGoogleDriveWriterBundle:Feed:Cell/batch.xml.twig',
						[
							'csv' => $csvFile,
							'fileId' => $file->getGoogleId(),
							'worksheetId' => $file->getSheetId(),
							'limit' => $limit,
							'offset' => $offset
						]
					)
				]
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

        return $this->api->request(
            sprintf(self::SPREADSHEET_WORKSHEETS . '/%s/private/full', $file->getGoogleId()),
            'POST',
            [
                'Accept'        => 'application/atom+xml',
                'Content-Type'  => 'application/atom+xml',
                'GData-Version' => '3.0',
            ],
			[
				'body' => $entryXml
			]
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

		$entryXml = "";

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
		$entryXml = "<?xml version='1.0' encoding='UTF-8'?>" . PHP_EOL . preg_replace('/\<entry.*\>\<id\>/', "<entry xmlns='http://www.w3.org/2005/Atom' xmlns:gs='http://schemas.google.com/spreadsheets/2006'><id>", $entryXml);

		return $this->api->request(
			sprintf(self::SPREADSHEET_WORKSHEETS . '/%s/private/full/%s', $file->getGoogleId(), $file->getSheetId()),
			'PUT',
			[
				'Accept'        => 'application/atom+xml',
				'Content-Type'  => 'application/atom+xml',
				'GData-Version' => '3.0',
				'If-Match' => '*'
			],
			[
				'body' => $entryXml
			]
		);
	}

	public function getWorksheetsFeed($fileId, $json = true)
	{
		$response = $this->api->request(
			self::SPREADSHEET_WORKSHEETS . '/' . $fileId . '/private/full' . ($json?'?alt=json':''),
			'GET',
			[
				'Accept' => $json?'application/json':'application/atom+xml',
				'GData-Version' => '3.0'
			]
		);

		return $json?json_decode($response->getBody(), true):$response->getBody()->getContents();
	}

	public function getWorksheets($fileId)
	{
		$response = json_decode($this->api->request(
			self::SPREADSHEET_WORKSHEETS . '/' . $fileId . '/private/full?alt=json' ,
			'GET',
			[
				'Accept' => 'application/json',
			    'GData-Version' => '3.0'
			]
		)->getBody(), true);

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
		return json_decode($this->api->request(
			sprintf(self::SPREADSHEET_CELL, $file->getGoogleId(), $file->getSheetId()) . '?alt=json',
			'GET',
			[
				'Accept' => 'application/json',
				'Content-Type' => 'application/atom+xml',
				'GData-Version' => '3.0'
			]
		)->getBody(), true);
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
