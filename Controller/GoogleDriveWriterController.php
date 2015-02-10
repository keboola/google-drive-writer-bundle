<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 14/01/14
 * Time: 14:01
 */

namespace Keboola\Google\DriveWriterBundle\Controller;


use Keboola\Google\DriveWriterBundle\Entity\File;
use Keboola\Google\DriveWriterBundle\Exception\ConfigurationException;
use Keboola\Google\DriveWriterBundle\Exception\ParameterMissingException;
use Keboola\Google\DriveWriterBundle\Writer\Configuration;
use Keboola\Google\DriveWriterBundle\Writer\Writer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Syrup\ComponentBundle\Controller\ApiController;

class GoogleDriveWriterController extends ApiController
{
	/** @var Configuration */
	protected $configuration;

	public function preExecute(Request $request)
	{
		parent::preExecute($request);

		$this->configuration = $this->container->get('wr_google_drive.configuration');
		$this->configuration->setStorageApi($this->storageApi);
	}

	protected function checkParams($required, $params)
	{
		foreach ($required as $r) {
			if (!isset($params[$r])) {
				throw new ParameterMissingException(sprintf("Parameter %s is missing.", $r));
			}
		}
	}

	/** Tokens */

	/**
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function postExternalAuthLinkAction(Request $request)
	{
		$post = $this->getPostJson($request);

		if (!isset($post['account'])) {
			throw new ParameterMissingException("Parameter 'account' is required");
		}

		if (!isset($post['referrer'])) {
			throw new ParameterMissingException("Parameter 'referrer' is required");
		}

		$token = $this->configuration->createToken();

		$referrer = $post['referrer'] . '?token=' . $token['token'] .'&account=' . $post['account'];

		$url = $this->generateUrl('keboola_google_drive_external_auth', array(
			'token'     => $token['token'],
			'account'   => $post['account'],
			'referrer'  => $referrer
		), true);

		return $this->createJsonResponse(array(
			'link'  => $url
		));
	}

	/** Configs */

	/**
	 * @return JsonResponse
	 */
	public function getConfigsAction()
	{
		$accounts = $this->configuration->getAccounts(true);

		$res = [];
		foreach ($accounts as $account) {
			$res[] = array_intersect_key($account, array_fill_keys(array('id', 'name', 'description'), 0));
		}

		return $this->createJsonResponse($res);
	}

	/**
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function postConfigsAction(Request $request)
	{
		$params = $this->getPostJson($request);
		$this->checkParams(['name'], $params);

		try {
			$this->configuration->exists();
		} catch (ConfigurationException $e) {
			$this->configuration->create();
		}

		$account = $this->configuration->getAccountBy('accountId', $this->configuration->getIdFromName($params['name']));
		if (null != $account) {
			throw new ConfigurationException('Account already exists');
		}

		$account = $this->configuration->addAccount($params);

		return $this->createJsonResponse([
			'id'            => $account->getAccountId(),
			'name'          => $account->getAccountName(),
			'description'   => $account->getDescription()
		]);
	}

	/**
	 * @param $id
	 * @return JsonResponse
	 */
	public function deleteConfigAction($id)
	{
		$this->configuration->removeAccount($id);

		return $this->createJsonResponse([], 204);
	}


	/** Accounts */

	/**
	 * @param $id
	 * @return JsonResponse
	 */
	public function getAccountsAction($id)
	{
		if ($id != null) {
			return $this->createJsonResponse($this->configuration->getAccountBy('accountId', $id, true));
		}
		return $this->createJsonResponse($this->configuration->getAccounts(true));
	}


	/** Files */

	/**
	 * @param $accountId
	 * @return JsonResponse
	 */
	public function getFilesAction($accountId)
	{
		$files = $this->configuration->getFiles($accountId);

		$res = [];

		/** @var File $file */
		foreach ($files as $file) {
			$res[] = $file->toArray();
		}

		return $this->createJsonResponse($res);
	}

	/**
	 * @param         $accountId
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function postFilesAction($accountId, Request $request)
	{
		$params = $this->getPostJson($request);

		foreach ($params as $fileData) {
			$this->checkParams([
				'tableId',
				'title'
			], $fileData);
		}

		$this->configuration->addFiles($accountId, $params);

		return $this->createJsonResponse([], 201);
	}

	public function getRemoteFilesAction($accountId, Request $request)
	{
		$account = $this->configuration->getAccount($accountId);

		$params = $request->query->all();

		/** @var Writer $writer */
		$writer = $this->container->get('wr_google_drive.writer');
		$writer->setConfiguration($this->configuration);

		$response = $writer->listFiles($account, $params);


		return $this->createJsonResponse($response);
	}

}
