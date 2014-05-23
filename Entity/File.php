<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 20/05/14
 * Time: 15:47
 */

namespace Keboola\Google\DriveWriterBundle\Entity;


class File
{
	protected $title;

	protected $pathname;

	protected $tableId;

	public function __construct(array $data)
	{
		$this->tableId = $data['tableId'];
		$this->title = $data['title'];
		$this->pathname = isset($data['pathname'])?$data['pathname']:null;
	}

	public function getTitle()
	{
		return $this->title;
	}

	public function getPathname()
	{
		return $this->pathname;
	}

	public function getTableId()
	{
		return $this->tableId;
	}

	public function setPathname($pathname)
	{
		$this->pathname = $pathname;

		return $this;
	}

	public function toArray()
	{
		return array(
			'tableId'   => $this->tableId,
			'title'     => $this->title,
			'config'    => '',
		);
	}
}
