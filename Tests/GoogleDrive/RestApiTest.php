<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 06/02/15
 * Time: 13:24
 */

namespace Keboola\Google\DriveWriterBundle\Tests\GoogleDrive;

use GuzzleHttp\Exception\ClientException;
use Keboola\Google\DriveWriterBundle\Entity\File;
use Keboola\Google\DriveWriterBundle\GoogleDrive\RestApi;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Syrup\Test\WebTestCase;

class RestApiTest extends WebTestCase
{
	protected $httpClient;

	/** @var RestApi */
	protected $restApi;

	/** @var ObjectEncryptor */
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
		$this->encryptor = $container->get('syrup.object_encryptor');

		$this->testCsvPath = realpath(__DIR__ . '/../data/test.csv');
		$this->test2CsvPath = realpath(__DIR__ . '/../data/test2.csv');
		$this->test3CsvPath = realpath(__DIR__ . '/../data/test3.csv');
		$this->expectedFeedPath = realpath(__DIR__ . '/../data/test-feed.xml');

		$this->initApi(ACCESS_TOKEN, REFRESH_TOKEN);
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
			'type' => File::TYPE_SHEET,
			'pathname' => $this->testCsvPath
		]);
	}

	public function testInsertFile()
	{
		$file = $this->createTestFile();
        $file->setType(File::TYPE_FILE);
		$response = $this->restApi->insertFile($file);

		$this->assertNotEmpty($response);
		$this->assertArrayHasKey('kind', $response);
		$this->assertEquals('drive#file', $response['kind']);
		$this->assertArrayHasKey('name', $response);
		$this->assertContains($file->getTitle(), $response['name']);
        $this->assertEquals('application/octet-stream', $response['mimeType']);

		// cleanup
		$file->setGoogleId($response['id']);
		$this->restApi->deleteFile($file);
	}

    public function testInsertSheet()
    {
        $file = $this->createTestFile();
        $response = $this->restApi->insertFile($file);

        $this->assertNotEmpty($response);
        $this->assertArrayHasKey('kind', $response);
        $this->assertEquals('drive#file', $response['kind']);
        $this->assertArrayHasKey('name', $response);
        $this->assertContains($file->getTitle(), $response['name']);
        $this->assertEquals('application/vnd.google-apps.spreadsheet', $response['mimeType']);

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
        $this->assertArrayHasKey('id', $response);
        $this->assertArrayHasKey('name', $response);
		$this->assertArrayHasKey('parents', $response);
		$this->assertArrayHasKey('webViewLink', $response);
        $this->assertContains($file->getTitle(), $response['name']);

        // cleanup
        $file->setGoogleId($response['id']);
        $this->restApi->deleteFile($file);
    }

	public function testUpdateFile()
	{
		$file = $this->createTestFile();
		$resFile = $this->restApi->insertFile($file);
		$response = $this->restApi->getFile($resFile['id']);

		$file->setGoogleId($response['id']);
		$file->setTitle('MovedTestFile');
		$file->setTargetFolder('0B8ceg4OWLR3ld0czTWxfd3RmQnc');
		$this->restApi->updateFile($file);

		$response2 = $this->restApi->getFile($resFile['id']);

		$this->assertNotEmpty($response2);
		$this->assertArrayHasKey('name', $response2);
		$this->assertContains('MovedTestFile', $response2['name']);
		$this->assertEquals($resFile['id'], $response2['id']);
		$this->assertEquals('0B8ceg4OWLR3ld0czTWxfd3RmQnc', $response2['parents'][0]);

		$file->setTitle('RenamedTestFile');
		$response3 = $this->restApi->updateFile($file);

		$this->assertEquals($file->getGoogleId(), $response3['id']);
		$this->assertContains($file->getTitle(), $response3['name']);

		// cleanup
		$this->restApi->deleteFile($file);
	}

	public function testDeleteFile()
	{
		$file = $this->createTestFile();
		$resFile = $this->restApi->insertFile($file);
		$response = $this->restApi->getFile($resFile['id']);
		$file->setGoogleId($response['id']);

		$response = $this->restApi->deleteFile($file);
		$this->assertEquals(204, $response->getStatusCode());
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

        $errors = $this->restApi->updateCells($file);

        $this->assertEmpty($errors['errors']);

		// cleanup
		$this->restApi->deleteFile($file);
	}

	public function testListFiles()
	{
		$file = $this->createTestFile();
		$fileRes = $this->restApi->insertFile($file);
		$response = $this->restApi->listFiles([
			'q' => "trashed=false and name='" . $fileRes['name'] . "'"
		]);

		$this->assertEquals($fileRes['name'], $response['files'][0]['name']);
	}
}
