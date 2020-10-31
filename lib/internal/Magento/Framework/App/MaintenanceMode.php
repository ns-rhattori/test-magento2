<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\App;

use IPTools\IP;
use IPTools\Range;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Event\Manager;
use Magento\Framework\Filesystem;

/**
 * Application Maintenance Mode
 */
class MaintenanceMode
{
    /**
     * Maintenance flag file name
     *
     * DO NOT consolidate this file and the IP allow list into one.
     * It is going to work much faster in 99% of cases: the isOn() will return false whenever file doesn't exist.
     */
    const FLAG_FILENAME = '.maintenance.flag';

    /**
     * IP-addresses file name
     */
    const IP_FILENAME = '.maintenance.ip';

    /**
     * Maintenance flag dir
     */
    const FLAG_DIR = DirectoryList::VAR_DIR;

    /**
     * Path to store files
     *
     * @var \Magento\Framework\Filesystem\Directory\WriteInterface
     */
    protected $flagDir;

    /**
     * @var Manager
     */
    private $eventManager;

    /**
     * @var Utility\IPAddressNormaliser
     */
    private $ipAddressNormaliser;

    /**
     * @param Filesystem $filesystem
     * @param Manager|null $eventManager
     * @param Utility\IPAddressNormaliser $ipAddressNormaliser
     */
    public function __construct(
        Filesystem $filesystem,
        ?Manager $eventManager = null,
        Utility\IPAddressNormaliser $ipAddressNormaliser = null
    ) {
        $this->flagDir = $filesystem->getDirectoryWrite(self::FLAG_DIR);
        $this->eventManager = $eventManager ?: ObjectManager::getInstance()->get(Manager::class);
        $this->ipAddressNormaliser = $ipAddressNormaliser ?:
            ObjectManager::getInstance()->get(Utility\IPAddressNormaliser::class);
    }

    /**
     * Checks whether mode is on
     *
     * Optionally specify an IP-address to compare against the allow list
     *
     * @param string $remoteAddr
     * @return bool
     */
    public function isOn($remoteAddr = '')
    {
        if (!$this->flagDir->isExist(self::FLAG_FILENAME)) {
            return false;
        }
        if ($remoteAddr) {
            $allowedAddresses = $this->getAddressInfo();
            $remoteAddress = new IP($remoteAddr);
            foreach ($allowedAddresses as $allowed) {
                if (Range::parse($allowed)->contains($remoteAddress)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Sets maintenance mode "on" or "off"
     *
     * @param bool $isOn
     * @return bool
     */
    public function set($isOn)
    {
        $this->eventManager->dispatch('maintenance_mode_changed', ['isOn' => $isOn]);

        if ($isOn) {
            return $this->flagDir->touch(self::FLAG_FILENAME);
        }
        if ($this->flagDir->isExist(self::FLAG_FILENAME)) {
            return $this->flagDir->delete(self::FLAG_FILENAME);
        }
        return true;
    }

    /**
     * Sets list of allowed IP addresses
     *
     * @param string $addresses
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function setAddresses($addresses)
    {
        $addresses = (string)$addresses;
        if (empty($addresses)) {
            if ($this->flagDir->isExist(self::IP_FILENAME)) {
                return $this->flagDir->delete(self::IP_FILENAME);
            }
            return true;
        }
        if (!preg_match('/^[^\s,]+(,[^\s,]+)*$/', $addresses)) {
            throw new \InvalidArgumentException("One or more IP-addresses is expected (comma-separated)\n");
        }
        $addressList = explode(',', $addresses);
        $addressList = $this->ipAddressNormaliser->execute($addressList);
        $addresses = implode(',', $addressList);
        $result = $this->flagDir->writeFile(self::IP_FILENAME, $addresses);
        return false !== $result;
    }

    /**
     * Get list of IP addresses effective for maintenance mode
     *
     * @return string[]
     */
    public function getAddressInfo()
    {
        if ($this->flagDir->isExist(self::IP_FILENAME)) {
            $temp = $this->flagDir->readFile(self::IP_FILENAME);
            return explode(',', trim($temp));
        } else {
            return [];
        }
    }
}
