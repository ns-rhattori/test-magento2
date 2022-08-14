<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Ui\DataProvider\Product;

use Magento\Framework\DB\Select;

/**
 * Collection which is used for rendering product list in the backend.
 *
 * Used for product grid and customizes behavior of the default Product collection for grid needs.
 */
class ProductCollection extends \Magento\Catalog\Model\ResourceModel\Product\Collection
{
    /**
     * Disables using of price index for grid rendering
     *
     * Admin area shouldn't use price index and should rely on actual product data instead.
     *
     * @codeCoverageIgnore
     * @return \Magento\Catalog\Model\ResourceModel\Product\Collection
     */
    protected function _productLimitationJoinPrice()
    {
        $this->_productLimitationFilters->setUsePriceIndex(false);
        return $this->_productLimitationPrice(true);
    }

    /**
     * @inheritdoc
     */
    public function getSize()
    {
        if ($this->_totalRecords === null) {
            if ($this->_scopeConfig->getValue('admin/grid/calculate_approximate_total_number_of_products')) {
                $sql = $this->getSelectCountSql();
                $estimatedCount = $this->analyzeCount($sql);

                if ($estimatedCount > $this->_scopeConfig->getValue('admin/grid/records_threshold')) {
                    $columns = $sql->getPart(Select::COLUMNS);
                    $sql->reset(Select::COLUMNS);

                    foreach ($columns as &$column) {
                        if ($column[1] instanceof \Zend_Db_Expr && $column[1] == "COUNT(DISTINCT e.entity_id)") {
                            $column[1] = new \Zend_Db_Expr('e.entity_id');
                        }
                    }
                    $sql->setPart(Select::COLUMNS, $columns);
                    $sql->limit($this->_scopeConfig->getValue('admin/grid/records_threshold'));
                    $query = new \Zend_Db_Expr('SELECT COUNT(*) FROM (' . $sql->assemble() . ') AS c');
                    $this->_totalRecords = (int)$this->getConnection()->query($query)->fetchColumn();
                    if ($this->_totalRecords === (int)$this->_scopeConfig->getValue('admin/grid/records_threshold')) {
                        $this->_totalRecords = $estimatedCount;
                    }
                } else {
                    return parent::getSize();
                }
                return $this->_totalRecords;
            }
            return parent::getSize();
        }
        return $this->_totalRecords;
    }

    /**
     * Analyze amount of entities in DB.
     *
     * @param $sql
     * @return int|mixed
     * @throws \Zend_Db_Select_Exception
     * @throws \Zend_Db_Statement_Exception
     */
    private function analyzeCount($sql)
    {
        $results = $this->getConnection()->query('EXPLAIN ' . $sql)->fetchAll();
        $alias = $this->getMainTableAlias();

        foreach ($results as $result) {
            if ($result['table'] == $alias) {
                return $result['rows'];
            }
        }

        return 0;
    }

    /**
     * Identify main table alias or its name if alias is not defined.
     *
     * @return string
     * @throws \LogicException
     * @throws \Zend_Db_Select_Exception
     */
    private function getMainTableAlias()
    {
        foreach ($this->getSelect()->getPart(Select::FROM) as $tableAlias => $tableMetadata) {
            if ($tableMetadata['joinType'] == 'from') {
                return $tableAlias;
            }
        }
        throw new \LogicException("Main table cannot be identified.");
    }
}
