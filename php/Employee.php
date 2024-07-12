<?php

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\Common\Persistence\Event\LifecycleEventArgs;
use Doctrine\ORM\Mapping\PostLoad;

/**
 * @Entity
 * @Table(
 *     name="employees"
 * )
 * @HasLifecycleCallbacks
 */
class Employee extends EntityBase implements \JsonSerializable
{
	/**
	 * @Id
	 * @Column(type="string", length=255)
	 * @ImportMap EMPLOYEE
	 * @ImportRequired
	 */
	protected $employeeID;

	/**
	 * @Column(type="text")
	 * @ImportMap LAST_NAME
	 */
	protected $lastName = '';

	/**
	 * @Column(type="text")
	 * @ImportMap FIRST_NAME
	 */
	protected $firstName = '';
	
	/**
	 * @Column(type="text")
	 */
	protected $email = '';
	
	/**
	 * @Column(type="text")
	 */
	protected $userName;
	
	/**
	 * @Column(type="text")
	 * @ImportMap POSITION
	 */
	protected $positionCode = '';
	
	/**
	 * @Column(type="text")
	 * @ImportMap JOB_CLASS
	 */
	protected $jobClass = '';

	/**
	 * @Column(type="text")
	 * @ImportMap DESCRIPTION
	 */
	protected $title = '';

	/**
	 * @Column(type="text")
	 * @ImportMap PROCESS_LEVEL
	 */
	protected $propertyID = '';

	/**
	 * @Column(type="text")
	 * @ImportMap LOCAT_CODE
	 */
	protected $property = '';

	/**
	 * @Column(type="text")
	 * @ImportMap DEPARTMENT
	 */
	protected $costCenter = '';
	
	/**
	 * @Column(type="date", nullable=true)
	 * @ImportMap DOH
	 */
	protected $hireDate = null;
	
	/**
	 * @Column(type="date",nullable=true)
	 */
	protected $terminationDate = null;

	/**
	 * @Column(type="text")
	 * @ImportMap DOB
	 */
	protected $birthDate = '';
	
	/**
	 * @Column(type="text")
	 * @ImportMap S_Employee
	 */
	protected $supervisorEmployeeID = '';
	
	/**
	 * @Column(type="text")
	 * @ImportMap D_Employee
	 */
	protected $directorEmployeeID = '';
	
	/**
	 * @Column(type="text")
	 * @ImportMap V_Employee
	 */
	protected $vpEmployeeId = '';
	
	/**
	 * @Column(type="text")
	 */
	protected $source = 'HR';
	
	/**
	 * @Column(type="text")
	 */
	protected $npiTemp = '';
	
	/**
	 * @Column(type="date",nullable=true)
	 */
	protected $created = null;
	
	/**
	 * @Column(type="date",nullable=true)
	 */
	protected $lastUpdated = null;
	
	/**
	 * @var bool false is the employee is terminated.
	 */
	protected $isActive;
	
	/**
	 * Sets isActive based on hireDate and terminationDate.
	 * 
	 * @PostLoad
	 * @param LifecycleEventArgs $args
	 */
	public function postLoad(LifecycleEventArgs $args) {
		$this->setIsActive(true);
		
		// If the term date is less than or equal to today set employee->active to false
		if ($this->getTerminationDate() != null && $this->getTerminationDate() <= new DateTime()) {
			$this->setIsActive(false);
		}
	}
	
	/**
	 * Get isActive.
	 * 
	 * @return bool
	 */
	public function getIsActive() {
		return $this->isActive;
	}
	
	/**
	 * Set isActive.
	 * 
	 * @param $isActive bool
	 * 
	 * @return Employee
	 */
	public function setIsActive($isActive) {
		$this->isActive = $isActive;
		return $this;
	}
	
	/**
	 * Get terminationDate
	 * 
	 * @return DateTime
	 */
	public function getTerminationDate() {
		return $this->terminationDate;
	}
	
	/**
	 * Set terminationDate
	 * 
	 * @param $terminationDate
	 * 
	 * @return Employee
	 */
	public function setTerminationDate($terminationDate) {
		$this->terminationDate = $terminationDate;
		return $this;
	}

    /**
     * Set employeeID
     *
     * @param string $employeeID
     *
     * @return Employee
     */
    public function setEmployeeID($employeeID)
    {
        $this->employeeID = $employeeID;

        return $this;
    }

    /**
     * Get employeeID
     *
     * @return string
     */
    public function getEmployeeID()
    {
        return $this->employeeID;
    }

    /**
     * Set lastName
     *
     * @param string $lastName
     *
     * @return Employee
     */
    public function setLastName($lastName)
    {
        $this->lastName = $lastName;

        return $this;
    }

    /**
     * Get lastName
     *
     * @return string
     */
    public function getLastName()
    {
        return $this->lastName;
    }

    /**
     * Set firstName
     *
     * @param string $firstName
     *
     * @return Employee
     */
    public function setFirstName($firstName)
    {
        $this->firstName = $firstName;

        return $this;
    }

    /**
     * Get firstName
     *
     * @return string
     */
    public function getFirstName()
    {
        return $this->firstName;
    }

    /**
     * Set positionCode
     *
     * @param string $positionCode
     *
     * @return Employee
     */
    public function setPositionCode($positionCode)
    {
        $this->positionCode = $positionCode;

        return $this;
    }

    /**
     * Get positionCode
     *
     * @return string
     */
    public function getPositionCode()
    {
        return $this->positionCode;
    }

    /**
     * Set title
     *
     * @param string $title
     *
     * @return Employee
     */
    public function setTitle($title)
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Get title
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * Set propertyID
     *
     * @param string $propertyID
     *
     * @return Employee
     */
    public function setpropertyID($propertyID)
    {
        $this->propertyID = $propertyID;

        return $this;
    }

    /**
     * Get propertyID
     *
     * @return string
     */
    public function getpropertyID()
    {
        return $this->propertyID;
    }

    /**
     * Set property
     *
     * @param string $property
     *
     * @return Employee
     */
    public function setProperty($property)
    {
        $this->property = $property;

        return $this;
    }

    /**
     * Get property
     *
     * @return string
     */
    public function getProperty()
    {
        return $this->property;
    }

    /**
     * Set costCenter
     *
     * @param string $costCenter
     *
     * @return Employee
     */
    public function setCostCenter($costCenter)
    {
        $this->costCenter = $costCenter;

        return $this;
    }

    /**
     * Get costCenter
     *
     * @return string
     */
    public function getCostCenter()
    {
        return $this->costCenter;
    }

    /**
     * Set supervisorEmployeeID
     *
     * @param int $supervisorEmployeeID
     *
     * @return Employee
     */
    public function setSupervisorEmployeeID($supervisorEmployeeID)
    {
        $this->supervisorEmployeeID = $supervisorEmployeeID;

        return $this;
    }

    /**
     * Get supervisorEmployeeID
     *
     * @return int
     */
    public function getSupervisorEmployeeID()
    {
        return $this->supervisorEmployeeID;
    }

    /**
     * Set hireDate
     *
     * @param \DateTime $hireDate
     *
     * @return Employee
     */
    public function setHireDate($hireDate)
    {
        $this->hireDate = $hireDate;

        return $this;
    }

    /**
     * Get hireDate
     *
     * @return \DateTime
     */
    public function getHireDate()
    {
        return $this->hireDate;
    }

    /**
     * Set birthDate
     *
     * @param string $birthDate
     *
     * @return Employee
     */
    public function setBirthDate($birthDate)
    {
        $this->birthDate = $birthDate;

        return $this;
    }

    /**
     * Get birthDate
     *
     * @return string
     */
    public function getBirthDate()
    {
        return $this->birthDate;
    }

    /**
     * Set directorEmployeeID
     *
     * @param string $directorEmployeeID
     *
     * @return Employee
     */
    public function setDirectorEmployeeID($directorEmployeeID)
    {
        $this->directorEmployeeID = $directorEmployeeID;

        return $this;
    }

    /**
     * Get directorEmployeeID
     *
     * @return string
     */
    public function getDirectorEmployeeID()
    {
        return $this->directorEmployeeID;
    }

    /**
     * Set vpEmployeeId
     *
     * @param string $vpEmployeeId
     *
     * @return Employee
     */
    public function setVpEmployeeId($vpEmployeeId)
    {
        $this->vpEmployeeId = $vpEmployeeId;

        return $this;
    }

    /**
     * Get vpEmployeeId
     *
     * @return string
     */
    public function getVpEmployeeId()
    {
        return $this->vpEmployeeId;
    }

    /**
     * Set userName
     *
     * @param string $userName
     *
     * @return Employee
     */
    public function setUserName($userName)
    {
        $this->userName = $userName;

        return $this;
    }

    /**
     * Get userName
     *
     * @return string
     */
    public function getUserName()
    {
        return $this->userName;
    }

    /**
     * Set email
     *
     * @param string $email
     *
     * @return Employee
     */
    public function setEmail($email)
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Get email
     *
     * @return string
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * Set source
     *
     * @param string $source
     *
     * @return Employee
     */
    public function setSource($source)
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Get source
     *
     * @return string
     */
    public function getSource()
    {
        return $this->source;
    }

    /**
     * Set npiTemp
     *
     * @param string $npiTemp
     *
     * @return Employee
     */
    public function setNpiTemp($npiTemp)
    {
        $this->npiTemp = $npiTemp;

        return $this;
    }

    /**
     * Get npiTemp
     *
     * @return string
     */
    public function getNpiTemp()
    {
        return $this->npiTemp;
    }

    /**
     * Set jobClass
     *
     * @param string $jobClass
     *
     * @return Employee
     */
    public function setJobClass($jobClass)
    {
        $this->jobClass = $jobClass;

        return $this;
    }

    /**
     * Get jobClass
     *
     * @return string
     */
    public function getJobClass()
    {
        return $this->jobClass;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     *
     * @return Employee
     */
    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get created
     *
     * @return \DateTime
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Set lastUpdated
     *
     * @param \DateTime $lastUpdated
     *
     * @return Employee
     */
    public function setLastUpdated($lastUpdated)
    {
        $this->lastUpdated = $lastUpdated;

        return $this;
    }

    /**
     * Get lastUpdated
     *
     * @return \DateTime
     */
    public function getLastUpdated()
    {
        return $this->lastUpdated;
    }
}
