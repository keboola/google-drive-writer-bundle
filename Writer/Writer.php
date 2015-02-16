<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 29/01/15
 * Time: 16:30
 */

namespace Keboola\Google\DriveWriterBundle\Writer;


use GuzzleHttp\Exception\BadResponseException;
use Keboola\Google\DriveWriterBundle\Entity\Account;
use Keboola\Google\DriveWriterBundle\Entity\File;
use Keboola\Google\DriveWriterBundle\GoogleDrive\RestApi;
use Monolog\Logger;
use Syrup\ComponentBundle\Exception\UserException;
use Syrup\ComponentBundle\Filesystem\Temp;

class Writer
{
	/** @var RestApi */
	protected $googleDriveApi;

	/** @var Logger */
	protected $logger;

//	/** @var Configuration */
//	protected $configuration;

//	/** @var Client */
//	protected $storageApi;

	/** @var Account */
	protected $currAccount;


	public function __construct(RestApi $googleDriveApi, Logger $logger)
	{
		$this->googleDriveApi = $googleDriveApi;
		$this->logger = $logger;
	}

//	public function setConfiguration($configuration)
//	{
//		$this->configuration = $configuration;
//		$this->storageApi = $this->configuration->getStorageApi();
//	}

	public function processFile(File $file)
	{
		if (null == $file->getGoogleId() || $file->isOperationCreate()) {

			// create new file
			$response = $this->googleDriveApi->insertFile($file);

			// update file with googleId
			$file->setGoogleId($response['id']);

		} else {
			// overwrite existing file
			try {
				$response = $this->googleDriveApi->updateFile($file);

			} catch (BadResponseException $e) {
				$statusCode = $e->getResponse()->getStatusCode();

				if ($statusCode == 404) {

					// file not found - create new one and issue a warning
					$response = $this->googleDriveApi->insertFile($file);
					$file->setGoogleId($response['id']);
				}

			}
		}

		return $file;
	}

	public function processSheet(File $file)
	{
		if (null == $file->getGoogleId() || null == $file->getSheetId() || $file->isOperationCreate()) {

			// create new file
			$fileRes = $this->googleDriveApi->insertFile($file);

			// get list of worksheets in file, there shall be only one
			$sheets = $this->googleDriveApi->getWorksheets($fileRes['id']);
			$sheet = array_shift($sheets);

			// update file
			$file->setGoogleId($fileRes['id']);
			$file->setSheetId($sheet['wsid']);

		} else if ($file->isOperationUpdate()) {

			// update content of existing file
			$response = $this->googleDriveApi->updateCells($file);

		} else {

			// @TODO: append sheet
		}

		return $file;
	}

	public function listFiles(Account $account, $params = [])
	{
		$this->initApi($account);

		return $this->googleDriveApi->listFiles($params);
	}

	public function refreshToken(Account $account)
	{
		$this->initApi($account);

		$response = $this->googleDriveApi->getApi()->refreshToken();
		return $response['access_token'];
	}

	public function initApi(Account $account)
	{
		$this->currAccount = $account;
		$this->googleDriveApi->getApi()->setCredentials($account->getAccessToken(), $account->getRefreshToken());
		$this->googleDriveApi->getApi()->setRefreshTokenCallback(array($this, 'refreshTokenCallback'));
	}

	public function refreshTokenCallback($accessToken, $refreshToken)
	{
		$account = $this->currAccount;
		$account->setAccessToken($accessToken);
		$account->setRefreshToken($refreshToken);
		$account->save();
	}

}
