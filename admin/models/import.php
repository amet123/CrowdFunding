<?php
/**
 * @package      CrowdFunding
 * @subpackage   Components
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2014 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// no direct access
defined('_JEXEC') or die;

jimport('joomla.application.component.modelform');

class CrowdFundingModelImport extends JModelForm
{
    protected function populateState()
    {
        $app = JFactory::getApplication();
        /** @var $app JApplicationAdministrator */

        // Load the filter state.
        $value = $app->getUserStateFromRequest('import.context', 'type', "currencies");
        $this->setState('import.context', $value);
    }

    /**
     * Method to get the record form.
     *
     * @param   array   $data     An optional array of data for the form to interogate.
     * @param   boolean $loadData True if the form is to load its own data (default case), false if not.
     *
     * @return  JForm   A JForm object on success, false on failure
     * @since   1.6
     */
    public function getForm($data = array(), $loadData = true)
    {
        // Get the form.
        $form = $this->loadForm($this->option . '.import', 'import', array('control' => 'jform', 'load_data' => $loadData));
        if (empty($form)) {
            return false;
        }

        return $form;
    }

    /**
     * Method to get the data that should be injected in the form.
     *
     * @return  mixed   The data for the form.
     * @since   1.6
     */
    protected function loadFormData()
    {
        // Check the session for previously entered form data.
        $data = JFactory::getApplication()->getUserState($this->option . '.edit.import.data', array());

        return $data;
    }

    public function extractFile($file, $destFolder)
    {
        // extract type
        $zipAdapter = JArchive::getAdapter('zip');
        $zipAdapter->extract($file, $destFolder);

        $dir = new DirectoryIterator($destFolder);

        $fileName = JFile::stripExt(basename($file));

        foreach ($dir as $fileinfo) {

            $currentFileName = JFile::stripExt($fileinfo->getFilename());

            if (!$fileinfo->isDot() and strcmp($fileName, $currentFileName) == 0) {
                $filePath = $destFolder . DIRECTORY_SEPARATOR . JFile::makeSafe($fileinfo->getFilename());
                break;
            }

        }

        return $filePath;
    }

    /**
     *
     * Import currencies from XML file.
     * The XML file is generated by the current extension ( CrowdFunding )
     *
     * @param string $file    A path to file
     * @param bool   $resetId Reset existing IDs with new ones.
     */
    public function importCurrencies($file, $resetId = false)
    {
        $xmlstr  = file_get_contents($file);
        $content = new SimpleXMLElement($xmlstr);

        if (!empty($content)) {

            // Check for existed currencies.
            $db    = $this->getDbo();
            $query = $db->getQuery(true);
            $query
                ->select("COUNT(*)")
                ->from($db->quoteName("#__crowdf_currencies", "a"));

            $db->setQuery($query);
            $result = $db->loadResult();

            if (!empty($result)) { // Update current currencies and insert newest.
                $this->updateCurrenices($content, $resetId);
            } else { // Insert new ones
                $this->insertCurrencies($content, $resetId);
            }
        }
    }

    protected function insertCurrencies($content, $resetId)
    {
        $items = array();

        $db = $this->getDbo();

        // Generate data for importing.
        foreach ($content as $item) {

            $title = JString::trim($item->title);
            $code  = JString::trim($item->abbr);
            if (!$title or !$code) {
                continue;
            }

            $id = (!$resetId) ? (int)$item->id : "null";

            $items[] = $id . "," . $db->quote($title) . "," . $db->quote($code) . "," . $db->quote(JString::trim($item->symbol)) . "," . (int)$item->position;
        }

        unset($content);

        $query = $db->getQuery(true);

        $query
            ->insert("#__crowdf_currencies")
            ->columns('id, title, abbr, symbol, position')
            ->values($items);

        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Update the currencies with new columns.
     */
    protected function updateCurrenices($content)
    {
        JLoader::register("CrowdFundingTableCurrency", JPATH_ADMINISTRATOR . DIRECTORY_SEPARATOR . "components" . DIRECTORY_SEPARATOR . "com_crowdfunding" . DIRECTORY_SEPARATOR . "tables" . DIRECTORY_SEPARATOR . "currency.php");
        $db = $this->getDbo();

        foreach ($content as $item) {

            $abbr = JString::trim($item->abbr);

            $keys = array("abbr" => $abbr);

            $table = new CrowdFundingTableCurrency($db);
            $table->load($keys);

            if (!$table->id) {
                $table->title    = JString::trim($item->title);
                $table->abbr     = $abbr;
                $table->position = 0;
            }

            // Update the symbol if missing.
            if (!$table->symbol and !empty($item->symbol)) {
                $table->symbol = JString::trim($item->symbol);
            }

            $table->store();
        }

    }

    /**
     * Import locations from TXT or XML file.
     * The TXT file comes from geodata.org
     * The XML file is generated by the current extension ( CrowdFunding )
     *
     * @param string $file    A path to file
     * @param bool   $resetId Reset existing IDs with new ones.
     * @param int   $minPopulation Reset existing IDs with new ones.
     */
    public function importLocations($file, $resetId = false, $minPopulation = 0)
    {
        $ext = JString::strtolower(JFile::getExt($file));

        switch ($ext) {
            case "xml":
                $this->importLocationsXml($file, $resetId);
                break;
            default: // TXT
                $this->importLocationsTxt($file, $resetId, $minPopulation);
                break;
        }
    }

    protected function importLocationsTxt($file, $resetId, $minPopulation)
    {
        $content = file($file);

        if (!empty($content)) {
            $items = array();
            $db    = $this->getDbo();

            unset($file);

            $i = 0;
            $x = 0;
            foreach ($content as $geodata) {

                $item = mb_split("\t", $geodata);

                // Check for missing ascii characters name
                $name = JString::trim($item[2]);
                if (!$name) {
                    // If missing ascii characters name, use utf-8 characters name
                    $name = JString::trim($item[1]);
                }

                // If missing name, skip the record
                if (!$name) {
                    continue;
                }

                if ($minPopulation > (int)$item[14]) {
                    continue;
                }

                $id = (!$resetId) ? JString::trim($item[0]) : "null";

                $items[$x][] =
                    $id . "," . $db->quote($name) . "," . $db->quote(JString::trim($item[4])) . "," .
                    $db->quote(JString::trim($item[5])) . "," . $db->quote(JString::trim($item[8])) . "," . $db->quote(JString::trim($item[17]));

                $i++;
                if ($i == 500) {
                    $x++;
                    $i = 0;
                }
            }

            unset($content);

            foreach ($items as $item) {
                $query = $db->getQuery(true);

                $query
                    ->insert($db->quoteName("#__crowdf_locations"))
                    ->columns('id, name, latitude, longitude, country_code, timezone')
                    ->values($item);

                $db->setQuery($query);
                $db->execute();
            }
        }
    }

    protected function importLocationsXml($file, $resetId)
    {
        $xmlstr  = file_get_contents($file);
        $content = new SimpleXMLElement($xmlstr);

        if (!empty($content)) {
            $items = array();
            $db    = $this->getDbo();

            $i = 0;
            $x = 0;
            foreach ($content->location as $item) {

                // Check for missing ascii characters name
                $name = JString::trim($item->name);

                // If missing name, skip the record
                if (!$name) {
                    continue;
                }

                // Reset ID
                $id = (!empty($item->id) and !$resetId) ? JString::trim($item->id) : "null";

                $items[$x][] =
                    $id . "," . $db->quote($name) . "," . $db->quote(JString::trim($item->latitude)) . "," . $db->quote(JString::trim($item->longitude)) . "," .
                    $db->quote(JString::trim($item->country_code)) . "," . $db->quote(JString::trim($item->timezone));
                $i++;
                if ($i == 500) {
                    $x++;
                    $i = 0;
                }
            }

            unset($item);
            unset($content);

            foreach ($items as $item) {
                $query = $db->getQuery(true);

                $query
                    ->insert($db->quoteName("#__crowdf_locations"))
                    ->columns('id, name, latitude, longitude, country_code, timezone')
                    ->values($item);

                $db->setQuery($query);
                $db->execute();
            }
        }
    }

    /**
     * Import countries from XML file.
     * The XML file is generated by the current extension ( CrowdFunding )
     * or downloaded from https://github.com/umpirsky/country-list
     *
     * @param string $file    A path to file
     * @param bool   $resetId Reset existing IDs with new ones.
     */
    public function importCountries($file, $resetId = false)
    {
        $xmlstr  = file_get_contents($file);
        $content = new SimpleXMLElement($xmlstr);

        if (!empty($content)) {

            // Check for existed countries.
            $db    = $this->getDbo();
            $query = $db->getQuery(true);
            $query
                ->select("COUNT(*)")
                ->from($db->quoteName("#__crowdf_countries", "a"));

            $db->setQuery($query);
            $result = $db->loadResult();

            if (!empty($result)) { // Update current countries and insert newest.
                $this->updateCountries($content, $resetId);
            } else { // Insert new ones
                $this->insertCountries($content, $resetId);
            }
        }
    }

    protected function insertCountries($content, $resetId)
    {
        $items = array();

        $db = $this->getDbo();

        foreach ($content->country as $item) {

            $name = JString::trim($item->name);
            $code = JString::trim($item->code);
            if (!$name or !$code) {
                continue;
            }

            $id = (!$resetId) ? (int)$item->id : "null";

            $items[] =
                $id . "," . $db->quote($name) . "," . $db->quote($code) . "," . $db->quote($item->code4) . "," . $db->quote($item->latitude) . "," .
                $db->quote($item->longitude) . "," . $db->quote($item->currency) . "," . $db->quote($item->timezone);
        }

        unset($content);

        $columns = array("id", "name", "code", "code4", "latitude", "longitude", "currency", "timezone");

        $query = $db->getQuery(true);

        $query
            ->insert($db->quoteName("#__crowdf_countries"))
            ->columns($db->quoteName($columns))
            ->values($items);

        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Update the countries with new columns.
     */
    protected function updateCountries($content)
    {
        JLoader::register("CrowdFundingTableCountry", JPATH_ADMINISTRATOR . DIRECTORY_SEPARATOR . "components" . DIRECTORY_SEPARATOR . "com_crowdfunding" . DIRECTORY_SEPARATOR . "tables" . DIRECTORY_SEPARATOR . "country.php");
        $db = $this->getDbo();

        foreach ($content->country as $item) {

            $code = JString::trim($item->code);

            $keys = array("code" => $code);

            $table = new CrowdFundingTableCountry($db);
            $table->load($keys);

            if (!$table->id) {
                $table->name = JString::trim($item->name);
                $table->code = $code;
            }

            $table->code4     = JString::trim($item->code4);
            $table->latitude  = JString::trim($item->latitude);
            $table->longitude = JString::trim($item->longitude);
            $table->currency  = JString::trim($item->currency);
            $table->timezone  = JString::trim($item->timezone);

            $table->store();
        }
    }

    /**
     * Import states from XML file.
     * The XML file is generated by the current extension.
     *
     * @param string $file A path to file
     */
    public function importStates($file)
    {
        $xmlstr  = file_get_contents($file);
        $content = new SimpleXMLElement($xmlstr);

        $generator = (string)$content->attributes()->generator;

        switch ($generator) {

            case "crowdfunding":
                $this->importCrowdFundingStates($content);
                break;

            default:
                $this->importUnofficialStates($content);
                break;
        }
    }

    /**
     * Import states that are based on locations,
     * and which are connected to locations IDs.
     */
    protected function importCrowdFundingStates($content)
    {
        if (!empty($content)) {

            $states = array();
            $db     = $this->getDbo();

            // Prepare data
            foreach ($content->state as $item) {

                // Check for missing state
                $stateCode = JString::trim($item->state_code);
                if (!$stateCode) {
                    continue;
                }

                $id = (int)$item->id;

                $states[$stateCode][] = "(" . $db->quoteName("id") . "=" . (int)$id . ")";

            }

            // Import data
            foreach ($states as $stateCode => $ids) {

                $query = $db->getQuery(true);

                $query
                    ->update($db->quoteName("#__crowdf_locations"))
                    ->set($db->quoteName("state_code") . "=" . $db->quote($stateCode))
                    ->where(implode(" OR ", $ids));

                $db->setQuery($query);
                $db->execute();
            }

            unset($states);
            unset($content);

        }

    }

    /**
     * Import states that are based on not official states data,
     * and which are not connected to locations IDs.
     *
     * @param SimpleXMLElement $content
     *
     * @todo remove this in next major version.
     */
    protected function importUnofficialStates($content)
    {
        if (!empty($content)) {

            $states = array();
            $db     = $this->getDbo();

            foreach ($content->city as $item) {

                // Check for missing ascii characters title
                $name = JString::trim($item->name);
                if (!$name) {
                    continue;
                }

                $code = JString::trim($item->state_code);

                $states[$code][] = "(" . $db->quoteName("name") . "=" . $db->quote($name) . " AND " . $db->quoteName("country_code") . "=" . $db->quote("US") . ")";
            }

            foreach ($states as $stateCode => $cities) {

                $query = $db->getQuery(true);

                $query
                    ->update("#__crowdf_locations")
                    ->set($db->quoteName("state_code") . " = " . $db->quote($stateCode))
                    ->where(implode(" OR ", $cities));

                $db->setQuery($query);
                $db->execute();
            }

            unset($states);
            unset($content);
        }
    }

    public function removeAll($resource)
    {
        if (!$resource) {
            throw new InvalidArgumentException("COM_CROWDFUNDING_ERROR_INVALID_RESOURCE_TYPE");
        }

        $db = JFactory::getDbo();

        switch ($resource) {

            case "countries":
                $db->truncateTable("#__crowdf_countries");
                break;

            case "currencies":
                $db->truncateTable("#__crowdf_currencies");
                break;

            case "locations":
                $db->truncateTable("#__crowdf_locations");
                break;
        }
    }
}
