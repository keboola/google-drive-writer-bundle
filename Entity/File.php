<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 20/05/14
 * Time: 15:47
 */

namespace Keboola\Google\DriveWriterBundle\Entity;


class File
{
	const TYPE_FILE = 'file';
	const TYPE_SHEET = 'sheet';

	protected $id;
	protected $title;
	protected $googleId;
	protected $type;
	protected $sheetId;
	protected $tableId;
	protected $incremental;
	protected $targetFolder;
	protected $targetFilename;
	protected $pathname;

	public function __construct(array $data)
	{
		$this->id = $data['id'];
		$this->title = $data['title'];
		$this->tableId = $data['tableId'];
		$this->googleId = isset($data['googleId'])?$data['googleId']:null;
		$this->type = isset($data['type'])?$data['type']:static::TYPE_FILE;
		$this->sheetId = isset($data['sheetId'])?$data['sheetId']:null;
		$this->incremental = isset($data['incremental'])?$data['incremental']:false;
		$this->targetFolder = isset($data['targetFolder'])?$data['targetFolder']:null;
		$this->targetFilename = isset($data['targetFilename'])?$data['targetFilename']:null;

		$this->pathname = isset($data['pathname'])?$data['pathname']:null;
	}

	public function getId()
	{
		return $this->id;
	}

	public function setId($id)
	{
		$this->id = $id;

		return $this;
	}

	public function getTitle()
	{
		return $this->title;
	}

	public function setTitle($title)
	{
		$this->title = $title;

		return $this;
	}

	public function getPathname()
	{
		return $this->pathname;
	}

	public function setPathname($pathname)
	{
		$this->pathname = $pathname;

		return $this;
	}

	public function setTableId($tableId)
	{
		$this->tableId = $tableId;
	}

	public function getTableId()
	{
		return $this->tableId;
	}

	public function getGoogleId()
	{
		return $this->googleId;
	}

	public function setGoogleId($googleId)
	{
		$this->googleId = $googleId;

		return $this;
	}

	public function toArray()
	{
		return array(
			'id'        => $this->id,
			'title'     => $this->title,
			'googleId'  => $this->googleId,
			'type'      => $this->type,
			'sheetId'   => $this->sheetId,
			'tableId'   => $this->tableId,
			'incremental'   => $this->incremental,
			'targetFolder'  => $this->targetFolder,
			'targetFilename'    => $this->targetFilename
		);
	}

	public function getType()
	{
		return $this->type;
	}

	public function isIncremental()
	{
		return (bool) $this->incremental;
	}

	public function setSheetId($sheetId)
	{
		$this->sheetId = $sheetId;
	}

	public function getSheetId()
	{
		return $this->sheetId;
	}
}
