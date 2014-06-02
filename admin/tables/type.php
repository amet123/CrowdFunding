<?php
/**
 * @package      CrowdFunding
 * @subpackage   Component
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2014 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

defined('_JEXEC') or die;

class CrowdFundingTableType extends JTable
{
    /**
     * @param JDatabaseDriver $db
     */
    public function __construct($db)
    {
        parent::__construct('#__crowdf_types', 'id', $db);
    }
}
