<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 29/04/14
 * Time: 17:16
 */

namespace Keboola\Google\DriveWriterBundle\Tests;

use Keboola\Csv\CsvFile;
use Keboola\Google\DriveWriterBundle\Entity\Account;
use Keboola\Google\DriveWriterBundle\Entity\File;
use Keboola\Google\DriveWriterBundle\GoogleDrive\RestApi;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Service\ObjectEncryptor;
use Symfony\Component\HttpFoundation\Response;
use Keboola\Syrup\Encryption\Encryptor;
use Keboola\Google\DriveWriterBundle\Writer\Configuration;
use Keboola\Syrup\Test\AbstractFunctionalTest;

class FunctionalTest extends AbstractFunctionalTest
{
	/** @var Configuration */
	protected $configuration;

	/** @var ObjectEncryptor */
	protected $encryptor;

	protected $componentName = 'wr-google-drive';

	protected $accountId = 'test';
	protected $accountName = 'Test';
	protected $googleId = '123456';
	protected $googleName = 'googleTestAccount';
	protected $email = 'test@keboola.com';
	protected $accessToken = 'accessToken';
	protected $refreshToken = 'refreshToken';

	protected $fileTitle = 'Google Drive TEST';
	protected $tableId = 'in.c-wr-google-drive.test';
	protected $rsTableId = 'in.c-wr-google-drive-rs.test';

	protected $testCsvPath;

	/** @var RestApi */
	protected $restApi;

	protected function setUp()
	{
		parent::setUp();

		$container = $this->httpClient->getContainer();

		$this->encryptor = $container->get('syrup.object_encryptor');
		$this->restApi = $container->get('wr_google_drive.rest_api');

		$this->testCsvPath = __DIR__ . '/data/test.csv';

		$this->configuration = $container->get('wr_google_drive.configuration');
		$this->configuration->setStorageApi($this->storageApiClient);

		try {
			$this->configuration->create();
		} catch (\Exception $e) {
			// bucket exists
		}

		$this->initEnv();
		$this->initApi($this->accessToken, $this->refreshToken);

		// Cleanup
		$sysBucketId = $this->configuration->getSysBucketId();
		$accTables = $this->storageApiClient->listTables($sysBucketId);
		foreach ($accTables as $table) {
			$this->storageApiClient->dropTable($table['id']);
		}

		if ($this->storageApiClient->tableExists($this->tableId)) {
			$this->storageApiClient->dropTable($this->tableId);
		}

		if ($this->storageApiClient->tableExists($this->rsTableId)) {
			$this->storageApiClient->dropTable($this->rsTableId);
		}

		if (!$this->storageApiClient->bucketExists('in.c-wr-google-drive')) {
			$this->storageApiClient->createBucket('wr-google-drive', Client::STAGE_IN, 'Google Drive IN bucket');
		}

		if (!$this->storageApiClient->bucketExists('in.c-wr-google-drive-rs')) {
			$this->storageApiClient->createBucket('wr-google-drive', Client::STAGE_IN, 'Google Drive IN bucket', 'redshift');
		}

		$csvFile = new CsvFile($this->testCsvPath);
		$this->storageApiClient->createTable('in.c-wr-google-drive', 'test', $csvFile);
		$this->storageApiClient->createTable('in.c-wr-google-drive-rs', 'test', $csvFile);
	}

	protected function initEnv()
	{
		$this->googleId = GOOGLE_ID;
		$this->googleName = GOOGLE_NAME;
		$this->email = EMAIL;
		$this->accessToken = ACCESS_TOKEN;
		$this->refreshToken = REFRESH_TOKEN;
		$this->tableId = TABLE_ID;
	}

	protected function initApi($accessToken, $refreshToken)
	{
		$this->restApi->getApi()->setCredentials($accessToken, $refreshToken);
		$this->restApi->getApi()->setRefreshTokenCallback([$this, 'refreshTokenCallback']);
	}

	public function refreshTokenCallback($accessToken, $refreshToken)
	{
		$account = $this->configuration->getAccount($this->accountId);
		$account->setAccessToken($accessToken);
		$account->setRefreshToken($refreshToken);
		$account->save();
	}

	protected function createConfig()
	{
		$this->configuration->addAccount([
			'id'            => $this->accountId,
			'name'          => $this->accountName,
			'accountName'   => $this->accountName,
			'description'   => 'Test Account created by PhpUnit test suite'
		]);
	}

	protected function createAccount()
	{
		$account = $this->configuration->getAccountBy('accountId', $this->accountId);
		$account->setAccountName($this->accountName);
		$account->setGoogleId($this->googleId);
		$account->setGoogleName($this->googleName);
		$account->setEmail($this->email);
		$account->setAccessToken($this->accessToken);
		$account->setRefreshToken($this->refreshToken);

		$account->save();
	}

	/**
	 * @param Account $account
	 */
	protected function assertAccount(Account $account)
	{
		$this->assertEquals($this->accountId, $account->getAccountId());
		$this->assertEquals($this->accountName, $account->getAccountName());
		$this->assertEquals($this->googleId, $account->getGoogleId());
		$this->assertEquals($this->googleName, $account->getGoogleName());
		$this->assertEquals($this->email, $account->getEmail());
		$this->assertEquals($this->accessToken, $account->getAccessToken());
		$this->assertEquals($this->refreshToken, $account->getRefreshToken());
	}


	/**
	 * Config
	 */

	public function testPostConfig()
	{
		$this->httpClient->request(
			'POST',
			$this->componentName . '/configs',
			[],
			[],
			[],
			json_encode([
				'name' => 'Test',
				'description' => 'Test Account created by PhpUnit test suite'
			])
		);

		$responseJson = $this->httpClient->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertEquals('test', $response['id']);
		$this->assertEquals('Test', $response['name']);
	}

	public function testGetConfig()
	{
		$this->createConfig();

		$this->httpClient->request('GET', $this->componentName . '/configs');

		$responseJson = $this->httpClient->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertEquals('test', $response[0]['id']);
		$this->assertEquals('Test', $response[0]['name']);
	}

	public function testDeleteConfig()
	{
		$this->createConfig();

		$this->httpClient->request('DELETE', $this->componentName . '/configs/test');

		/* @var Response $response */
		$response = $this->httpClient->getResponse();

		$accounts = $this->configuration->getAccounts(true);

		$this->assertEquals(204, $response->getStatusCode());
		$this->assertEmpty($accounts);
	}

	/**
	 * Accounts
	 */

	public function testGetAccount()
	{
		$this->createConfig();
		$this->createAccount();

		$this->httpClient->request('GET', $this->componentName . '/accounts/' . $this->accountId);

        $this->assertEquals(200, $this->httpClient->getResponse()->getStatusCode());

		$responseJson = $this->httpClient->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$account = $this->configuration->getAccountBy('accountId', $response['accountId']);

		$this->assertAccount($account);
	}

	public function testGetAccounts()
	{
		$this->createConfig();
		$this->createAccount();

		$this->httpClient->request(
			'GET', $this->componentName . '/accounts'
		);

		$responseJson = $this->httpClient->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertNotEmpty($response);

		$accountArr = array_shift($response);

		$this->assertAccount($this->configuration->getAccountBy('accountId', $accountArr['accountId']));
	}

	/**
	 * Files
	 */

	public function testPostFiles()
	{
		$this->createConfig();
		$this->createAccount();

		$this->httpClient->request(
			'POST',
			$this->componentName . '/files/' . $this->accountId,
			[],
			[],
			[],
			json_encode([
                'tableId' => $this->tableId,
                'title' => $this->fileTitle,
                'type' => 'file'
            ])
		);

		$response = $this->httpClient->getResponse();

		$this->assertEquals(201, $response->getStatusCode());

		$account = $this->configuration->getAccount($this->accountId);
		$files = $account->getFiles();

		$this->assertNotEmpty($files);
		$file = array_shift($files);

		$this->assertEquals($this->fileTitle, $file->getTitle());
		$this->assertEquals('file', $file->getType());
		$this->assertEquals($this->tableId, $file->getTableId());
	}

    public function testPutFiles()
    {
        $this->createConfig();
        $this->createAccount();

        // add file to config
        $fileId = $this->configuration->addFile($this->accountId, [
            'title' => 'Test Sheet',
            'tableId' => 'empty',
            'type' => 'sheet',
            'pathname' => $this->testCsvPath
        ]);

        // call the API
        $this->httpClient->request(
            'PUT',
            $this->componentName . '/files/' . $this->accountId . '/' . $fileId,
            [],
            [],
            [],
            json_encode([
                'title' => 'Test Sheet Update',
                'tableId' => 'updated'
            ])
        );

        $response = $this->httpClient->getResponse();
        $responseJson = json_decode($response->getContent(), true);

        $resFile = array_shift($responseJson);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Test Sheet Update', $resFile['title']);
        $this->assertEquals('updated', $resFile['tableId']);
    }

	public function testGetFiles()
	{
		$this->createConfig();
		$this->createAccount();

		// add files to config
		$file = new File([
			'id' => 0,
			'title' => 'Test Sheet',
			'tableId' => 'empty',
			'type' => 'sheet',
			'pathname' => $this->testCsvPath
		]);

		$file2 = new File([
			'id' => 1,
			'title' => 'Test File',
			'tableId' => 'empty2',
			'type' => 'file',
			'pathname' => $this->testCsvPath
		]);

		$this->configuration->addFile($this->accountId, $file->toArray());
        $this->configuration->addFile($this->accountId, $file2->toArray());

		// call the API
		$this->httpClient->request(
			'GET',
			$this->componentName . '/files/' . $this->accountId
		);

		$response = $this->httpClient->getResponse();

		$this->assertEquals(200, $response->getStatusCode());

		$files = json_decode($response->getContent(), true);

		$this->assertCount(2, $files);

		$fileInConfig = array_shift($files);

		$this->assertEquals('Test Sheet', $fileInConfig['title']);
		$this->assertEquals('empty', $fileInConfig['tableId']);
		$this->assertEquals('sheet', $fileInConfig['type']);
        $this->assertEquals(File::OPERATION_UPDATE, $fileInConfig['operation']);
        $this->assertArrayHasKey('targetFolder', $fileInConfig);
        $this->assertArrayHasKey('sheetId', $fileInConfig);

		$fileInConfig = array_shift($files);

		$this->assertEquals('Test File', $fileInConfig['title']);
		$this->assertEquals('empty2', $fileInConfig['tableId']);
		$this->assertEquals('file', $fileInConfig['type']);
        $this->assertEquals(File::OPERATION_UPDATE, $fileInConfig['operation']);
        $this->assertArrayHasKey('targetFolder', $fileInConfig);
        $this->assertArrayHasKey('sheetId', $fileInConfig);
	}

	public function testDeleteFile()
	{
		$this->createConfig();
		$this->createAccount();

		// add files to config
		$this->configuration->addFile($this->accountId, [
            'title' => 'Test Sheet',
            'tableId' => 'empty'
		]);

		$files = $this->configuration->getFiles($this->accountId);

		/** @var File $file */
		$file = array_shift($files);

		// call the API
		$this->httpClient->request(
			'DELETE',
			$this->componentName . '/files/' . $this->accountId . '/' . $file->getId()
		);

		$response = $this->httpClient->getResponse();

		$this->assertEquals(204, $response->getStatusCode());

		$filesInConfig = $this->configuration->getFiles($this->accountId);

		$this->assertEmpty($filesInConfig);
	}

    public function testGetRemoteFile()
    {
		$this->createConfig();
		$this->createAccount();

		$file = new File([
			'id' => 0,
			'title' => 'Test Sheet',
			'tableId' => $this->tableId,
			'targetFolder' => '0B8ceg4OWLR3lelQzMm9pcDEyNHc',
			'type' => 'sheet'
		]);

		// add files to config
		$this->configuration->addFile($this->accountId, $file->toArray());

		// run
		$job = $this->processJob($this->componentName . '/run');
		$this->assertEquals('success', $job->getStatus());

		$files = $this->configuration->getFiles($this->accountId);

		/** @var File $file */
		$file = array_shift($files);

		// call the API
		$this->httpClient->restart();
		$this->httpClient->request(
			'GET',
			sprintf(
				'%s/remote-file/%s/%s',
				$this->componentName,
				$this->accountId,
				$file->getGoogleId()
			)
		);

		$response = $this->httpClient->getResponse();
		$body = json_decode($response->getContent(), true);

		$this->assertEquals(200, $response->getStatusCode());
		$this->assertArrayHasKey('id', $body);
		$this->assertArrayHasKey('name', $body);
		$this->assertArrayHasKey('title', $body);
		$this->assertArrayHasKey('parents', $body);
		$this->assertArrayHasKey('mimeType', $body);
		$this->assertArrayHasKey('trashed', $body);
		$this->assertArrayHasKey('alternateLink', $body);
		$this->assertArrayHasKey('webViewLink', $body);

		$this->assertNotEmpty($body['id']);
		$this->assertNotEmpty($body['name']);
		$this->assertNotEmpty($body['title']);
		$this->assertNotEmpty($body['alternateLink']);
		$this->assertNotEmpty($body['webViewLink']);
    }

	/**
	 * External
	 */

	public function testExternalLink()
	{
		$this->createConfig();
		$this->createAccount();

		$referrerUrl = $this->httpClient
			->getContainer()
			->get('router')
			->generate('keboola_google_drive_writer_post_external_auth_link', [], true);

		$this->httpClient->followRedirects();
		$this->httpClient->request(
			'POST',
			$this->componentName . '/external-link',
			[],
			[],
			[],
			json_encode([
				'account'   => $this->accountId,
				'referrer'  => $referrerUrl
			])
		);

		$responseJson = $this->httpClient->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertArrayHasKey('link', $response);
		$this->assertNotEmpty($response['link']);
	}

	/**
	 * Run
	 */

	public function testRun()
	{
        $this->createConfig();
        $this->createAccount();

        // add files to config
        $file = new File([
            'id' => 0,
            'title' => 'Test Sheet',
            'tableId' => $this->tableId,
			'targetFolder' => '0B8ceg4OWLR3lelQzMm9pcDEyNHc',
            'type' => 'sheet'
        ]);

        $file2 = new File([
            'id' => 1,
            'title' => 'Test File',
            'tableId' => $this->tableId,
			'targetFolder' => '0B8ceg4OWLR3lelQzMm9pcDEyNHc',
            'type' => 'file'
        ]);

		$file3 = new File([
			'id' => 2,
			'title' => 'Test Sheet Redshift',
			'tableId' => $this->rsTableId,
			'targetFolder' => '0B8ceg4OWLR3lelQzMm9pcDEyNHc',
			'type' => 'sheet'
		]);

		$file4 = new File([
			'id' => 3,
			'title' => 'Test File Redshift',
			'tableId' => $this->rsTableId,
			'targetFolder' => '0B8ceg4OWLR3lelQzMm9pcDEyNHc',
			'type' => 'file'
		]);

		$filesToSync = [$file, $file2, $file3, $file4];

		/** @var $f File */
		foreach ($filesToSync as $f) {
			$this->configuration->addFile($this->accountId, $f->toArray());
		}

        // run
        $job = $this->processJob($this->componentName . '/run');
		$this->assertEquals('success', $job->getStatus());

		// update files
		$files = $this->configuration->getFiles($this->accountId);

		/** @var File $file */
		$file = $files[0];
		$file->setTitle('Test Sheet Updated');
		$this->configuration->updateFile($this->accountId, $file->getId(), $file->toArray());

		/** @var File $file2 */
		$file2 = $files[1];
		$file2->setTitle('Test File Updated');
		$this->configuration->updateFile($this->accountId, $file2->getId(), $file2->toArray());

		/** @var File $file3 */
		$file3 = $files[2];

		/** @var File $file4 */
		$file4 = $files[3];

		$this->httpClient->restart();
		$job = $this->processJob($this->componentName . '/run');
		$this->assertEquals('success', $job->getStatus());

		$updatedFiles = $this->configuration->getFiles($this->accountId);

		/** @var File $updatedFile */
		$updatedFile = $updatedFiles[0];
		/** @var File $updatedFile2 */
		$updatedFile2 = $updatedFiles[1];

		$this->assertEquals($file->getGoogleId(), $updatedFile->getGoogleId());
		$this->assertEquals($file2->getGoogleId(), $updatedFile2->getGoogleId());
		$this->assertEquals('Test File Redshift', $file4->getTitle());
		$this->assertEquals('Test Sheet Redshift', $file3->getTitle());
    }

    public function testRunExternal()
    {
        $processedFiles = $this->storageApiClient->listFiles(
            (new ListFilesOptions())->setTags(['wr-google-drive-processed'])
        );

        foreach ($processedFiles as $f) {
            $this->storageApiClient->deleteFileTag($f['id'], 'wr-google-drive-processed');
        }

        $job = $this->processJob($this->componentName . '/run', [
            'external' => [
                'account' => [
                    'email' => $this->email,
                    'accessToken' => $this->encryptor->encrypt($this->accessToken),
                    'refreshToken' => $this->encryptor->encrypt($this->refreshToken)
                ],
                'query' => '+tags:tde',
				'targetFolder' => '0B8ceg4OWLR3ld0czTWxfd3RmQnc'
            ]
        ]);

        $this->assertEquals('success', $job->getStatus());

		// test if external account was deleted
		$accounts = $this->configuration->getAccounts();

		/** @var Account $account */
		foreach ($accounts as $account) {
			$this->assertNotContains('external', $account->getAccountId());
		}
    }
}
