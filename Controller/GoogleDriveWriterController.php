<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 14/01/14
 * Time: 14:01
 */

namespace Keboola\Google\DriveWriterBundle\Controller;


use Keboola\Google\DriveWriterBundle\Exception\ParameterMissingException;
use Keboola\Google\DriveWriterBundle\GoogleDriveWriter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Syrup\ComponentBundle\Controller\ApiController;
use Syrup\ComponentBundle\Exception\UserException;

class GoogleDriveWriterController extends ApiController
{
	/** Tokens */

	public function postExternalAuthLinkAction()
	{
		$post = $this->getPostJson($this->getRequest());

		if (!isset($post['account'])) {
			throw new ParameterMissingException("Parameter 'account' is required");
		}

		if (!isset($post['referrer'])) {
			throw new ParameterMissingException("Parameter 'referrer' is required");
		}

		$token = $this->getComponent()->getToken();

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

	public function getConfigsAction()
	{
		return $this->getJsonResponse($this->getComponent()->getConfigs());
	}

	public function postConfigsAction()
	{
		$account = $this->getComponent()->postConfigs($this->getPostJson($this->getRequest()));

		return $this->getJsonResponse(array(
			'id'    => $account->getAccountId(),
			'name'  => $account->getAccountName(),
			'description'   => $account->getDescription()
		));
	}

	public function deleteConfigAction($id)
	{
		$this->getComponent()->deleteConfig($id);

		return $this->getJsonResponse(array(), 204);
	}


	/** Accounts */

	public function getAccountsAction($id)
	{
		if ($id != null) {
			return $this->getJsonResponse($this->getComponent()->getAccount($id));
		}
		return $this->getJsonResponse($this->getComponent()->getAccounts());
	}


	/** Files */

	public function getFilesAction($accountId)
	{
		return $this->getJsonResponse($this->getComponent()->getFiles($accountId));
	}

	public function postFilesAction($accountId)
	{
		$params = $this->getPostJson($this->getRequest());

		$this->getComponent()->postFiles($accountId, $params);

		return $this->getJsonResponse(array(), 201);
	}

	/**
	 * @return GoogleDriveWriter
	 */
	protected function getComponent()
	{
		return $this->component;
	}

	protected function getJsonResponse(array $data, $status = 200)
	{
		$response = new JsonResponse($data, $status);
		$response->headers->set('Access-Control-Allow-Origin', '*');

		return $response;
	}

}
