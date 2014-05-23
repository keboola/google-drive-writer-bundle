<?php
/**
 * RestApi.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 24.6.13
 */

namespace Keboola\Google\DriveWriterBundle\GoogleDrive;

use Guzzle\Http\Message\EntityEnclosingRequest;
use Guzzle\Http\Message\Header;
use Keboola\Google\ClientBundle\Google\RestApi as GoogleApi;
use Keboola\Google\DriveWriterBundle\Entity\File;

class RestApi
{
	/**
	 * @var GoogleApi
	 */
	protected $api;

	const FILE_UPLOAD = 'https://www.googleapis.com/upload/drive/v2/files';

	public function __construct(GoogleApi $api)
	{
		$this->api = $api;
	}

	public function getApi()
	{
		return $this->api;
	}

	public function uploadFile(File $file)
	{
		/** @var EntityEnclosingRequest $request */
		$request = $this->api->request(self::FILE_UPLOAD . '?uploadType=resumable&convert=true', 'POST', array(
			'Content-Type'              => 'application/json; charset=UTF-8',
			'X-Upload-Content-Type'     => 'text/csv',
			'X-Upload-Content-Length'   => filesize($file->getPathname())
		));

		$request->setBody(json_encode(array(
			'title' => $file->getTitle()
		)));

		$respone = $request->send();

		/** @var Header $locationHeader */
		$locationHeader = $respone->getHeader('Location');
		$location = $locationHeader->toArray();
		$locationUri = $location[0];

		$respone = $this->api->call($locationUri . '&convert=true', 'PUT', array(
			'Content-Type'      => 'text/csv',
			'Content-Length'    => filesize($file->getPathname())
		), fopen($file->getPathname(), 'r'));


		return $respone->json();
	}


}
