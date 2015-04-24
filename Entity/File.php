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

	const OPERATION_CREATE = 'create';
	const OPERATION_UPDATE = 'update';
	const OPERATION_APPEND = 'append';

	protected $id;
	protected $title;
	protected $googleId;
	protected $type;
	protected $sheetId;
	protected $tableId;
	protected $operation;
	protected $targetFolder;
	protected $pathname;
    protected $size;

	public function __construct(array $data)
	{
		$this->id = $data['id'];
		$this->title = $data['title'];
		$this->tableId = isset($data['tableId'])?$data['tableId']:null;
		$this->googleId = isset($data['googleId'])?$data['googleId']:null;
		$this->type = isset($data['type'])?$data['type']:static::TYPE_FILE;
		$this->sheetId = isset($data['sheetId'])?$data['sheetId']:null;
		$this->operation = isset($data['operation'])?$data['operation']:static::OPERATION_UPDATE;
		$this->targetFolder = isset($data['targetFolder'])?$data['targetFolder']:null;
        $this->size = isset($data['size'])?$data['size']:null;

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
		return [
			'id' => $this->id,
			'title' => $this->title,
			'googleId' => $this->googleId,
			'type' => $this->type,
			'sheetId' => $this->sheetId,
			'tableId' => $this->tableId,
			'operation' => $this->operation,
			'targetFolder' => $this->targetFolder
		];
	}

	public function setType($type)
	{
		$this->type = $type;

		return $this;
	}

	public function getType()
	{
		return $this->type;
	}

	public function setSheetId($sheetId)
	{
		$this->sheetId = $sheetId;

		return $this;
	}

	public function getSheetId()
	{
		return $this->sheetId;
	}

    public function setOperation($operation)
    {
        $this->operation = $operation;

        return $this;
    }

	public function isOperationCreate()
	{
		return ($this->operation == static::OPERATION_CREATE);
	}

	public function isOperationUpdate()
	{
		return ($this->operation == static::OPERATION_UPDATE);
	}

	public function isOperationAppend()
	{
		return ($this->operation == static::OPERATION_APPEND);
	}

    public function setTargetFolder($folder)
    {
        $this->targetFolder = $folder;

        return $this;
    }

    public function getTargetFolder()
    {
        return $this->targetFolder;
    }

    public function getSize()
    {
        if ($this->size != null) {
            return $this->size;
        } else if ($this->pathname != null) {
            return filesize($this->pathname);
        }
        return null;
    }
}
