<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 06/02/15
 * Time: 13:24
 */

namespace Keboola\Google\DriveWriterBundle\Tests\GoogleDrive;

use GuzzleHttp\Message\Response;
use Keboola\Google\DriveWriterBundle\Entity\File;
use Keboola\Google\DriveWriterBundle\GoogleDrive\RestApi;
use Symfony\Component\CssSelector\CssSelector;
use Symfony\Component\DomCrawler\Crawler;
use Syrup\ComponentBundle\Encryption\Encryptor;
use Syrup\ComponentBundle\Test\WebTestCase;

class RestApiTest extends WebTestCase
{
	protected $httpClient;

	/** @var RestApi */
	protected $restApi;

	/** @var Encryptor */
	protected $encryptor;

	protected $testCsvPath;
	protected $test2CsvPath;
	protected $test3CsvPath;

	protected $expectedFeedPath;

	public function setUp()
	{
		$this->httpClient = $this->createClient();

		$container = $this->httpClient->getContainer();

		$this->restApi = $container->get('wr_google_drive.rest_api');
		$this->encryptor = $container->get('syrup.encryptor');

		$this->testCsvPath = realpath(__DIR__ . '/../data/test.csv');
		$this->test2CsvPath = realpath(__DIR__ . '/../data/test2.csv');
		$this->test3CsvPath = realpath(__DIR__ . '/../data/test3.csv');
		$this->expectedFeedPath = realpath(__DIR__ . '/../data/test-feed.xml');

		$accessToken = $this->encryptor->decrypt(ACCESS_TOKEN);
		$refreshToken = $this->encryptor->decrypt(REFRESH_TOKEN);

		$this->initApi($accessToken, $refreshToken);
	}

	protected function initApi($accessToken, $refreshToken)
	{
		$this->restApi->getApi()->setCredentials($accessToken, $refreshToken);
		$this->restApi->getApi()->setRefreshTokenCallback([$this, 'refreshTokenCallback']);
	}

	public function refreshTokenCallback($accessToken, $refreshToken)
	{
		//@TODO: save new tokens to config.php
	}

	protected function createTestFile()
	{
		return new File([
			'id' => 0,
			'title' => 'Test Sheet',
			'tableId' => 'empty',
			'type' => 'sheet',
			'pathname' => $this->testCsvPath
		]);
	}

	public function testInsertFile()
	{
		$file = $this->createTestFile();
		$response = $this->restApi->insertFile($file);

		$this->assertNotEmpty($response);
		$this->assertArrayHasKey('kind', $response);
		$this->assertEquals('drive#file', $response['kind']);
		$this->assertArrayHasKey('title', $response);
		$this->assertContains($file->getTitle(), $response['title']);

		// cleanup
		$file->setGoogleId($response['id']);
		$this->restApi->deleteFile($file);
	}

    public function testGetFile()
    {
        $file = $this->createTestFile();
        $resFile = $this->restApi->insertFile($file);

        $response = $this->restApi->getFile($resFile['id']);

        $this->assertNotEmpty($response);
        $this->assertArrayHasKey('kind', $response);
        $this->assertEquals('drive#file', $response['kind']);
        $this->assertArrayHasKey('title', $response);
        $this->assertContains($file->getTitle(), $response['title']);

        // cleanup
        $file->setGoogleId($response['id']);
        $this->restApi->deleteFile($file);
    }

	public function testGetWorksheets()
	{
		$file = $this->createTestFile();
		$fileResponse = $this->restApi->insertFile($file);
		$fileGoogleId = $fileResponse['id'];

		$response = $this->restApi->getWorksheets($fileGoogleId);

		// hence this is a new file, there will be only one sheet
		$this->assertCount(1, $response);
		$sheet = array_shift($response);
		$this->assertArrayHasKey('id', $sheet);
		$this->assertArrayHasKey('wsid', $sheet);

		// cleanup
		$file->setGoogleId($fileGoogleId);
		$this->restApi->deleteFile($file);
	}

	public function testGetWorksheetsFeed()
	{
		$file = $this->createTestFile();
		$fileResponse = $this->restApi->insertFile($file);
		$fileGoogleId = $fileResponse['id'];

		$response = $this->restApi->getWorksheetsFeed($fileGoogleId);

		$this->assertEquals($file->getTitle(), $response['feed']['title']['$t']);

		// cleanup
		$file->setGoogleId($fileGoogleId);
		$this->restApi->deleteFile($file);
	}

	public function testGetCellsFeed()
	{
		$file = $this->createTestFile();
		$fileRes = $this->restApi->insertFile($file);
		$sheets = $this->restApi->getWorksheets($fileRes['id']);
		$sheet = array_shift($sheets);
		$file->setGoogleId($fileRes['id']);
		$file->setSheetId($sheet['wsid']);

		$response = $this->restApi->getCellsFeed($file);

		$this->assertCount(24, $response['feed']['entry']);

		// cleanup
		$this->restApi->deleteFile($file);
	}

    public function testCreateWorksheet()
    {
        // create new file type='sheet'
        $file = $this->createTestFile();
        $fileRes = $this->restApi->insertFile($file);

        // this file now has one empty sheet
        $sheets = $this->restApi->getWorksheets($fileRes['id']);
        $sheet = array_shift($sheets);
        $file->setGoogleId($fileRes['id']);
        $file->setSheetId($sheet['wsid']);

        // create second sheet in the same file
        $file2 = new File([
            'id' => 1,
            'title' => 'Test Sheet2',
            'googleId' => $fileRes['id'],
            'tableId' => 'empty',
            'type' => 'sheet',
            'pathname' => $this->test2CsvPath
        ]);

        // create new worksheet
        $response = $this->restApi->createWorksheet($file2);

        $this->assertEquals(201, $response->getStatusCode());

        // cleanup
        $this->restApi->deleteFile($file);
    }

	public function testUpdateWorksheet()
	{
		$file = $this->createTestFile();
		$fileRes = $this->restApi->insertFile($file);
		$sheets = $this->restApi->getWorksheets($fileRes['id']);
		$sheet = array_shift($sheets);
		$file->setGoogleId($fileRes['id']);
		$file->setSheetId($sheet['wsid']);

		$file->setPathname($this->test3CsvPath);

		$response = $this->restApi->updateWorksheet($file);

		$this->assertEquals(200, $response->getStatusCode());

		// cleanup
		$this->restApi->deleteFile($file);
	}

	public function testUpdateCells()
	{
		$file = $this->createTestFile();
		$fileRes = $this->restApi->insertFile($file);
		$sheets = $this->restApi->getWorksheets($fileRes['id']);
		$sheet = array_shift($sheets);
		$file->setGoogleId($fileRes['id']);
		$file->setSheetId($sheet['wsid']);
		$file->setPathname($this->test3CsvPath);

		// update sheet size
		$this->restApi->updateWorksheet($file);

        /** @var Response $res */
        $responses = $this->restApi->updateCells($file);
        $res = $responses[0];

        $this->assertEquals(200, $res->getStatusCode());

        $xmlFeed = $res->getBody()->getContents();

        $crawler = new Crawler($xmlFeed);

        CssSelector::disableHtmlExtension();

        $this->assertNotEmpty($crawler->filter('default|entry batch|status'));

        /** @var \DOMElement $entry */
        foreach ($crawler->filter('default|entry batch|status') as $entry) {
            $this->assertEquals('Success', $entry->getAttribute('reason'));
        }

		// cleanup
		$this->restApi->deleteFile($file);
	}
}
