<?php

namespace UndefinedOffset\SortableGridField\Forms;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\Controller;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extensible;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_DataManipulator;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Forms\GridField\GridFieldFilterHeader;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\ORM\RelationList;
use SilverStripe\ORM\SS_List;
use SilverStripe\ORM\UnsavedRelationList;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\ArrayData;
use SilverStripe\View\Requirements;

/**
 * This component provides a checkbox which when checked enables drag-and-drop re-ordering of elements displayed in a {@link GridField}
 *
 * @package forms
 */
class GridFieldSortableRows implements GridField_HTMLProvider, GridField_ActionProvider, GridField_DataManipulator
{
    /** @var string */
    protected $sortColumn;

    /** @var bool */
    protected $disable_selection = true;

    /** @var bool */
    protected $append_to_top = false;

    /** @var null|string */
    protected $update_versioned_stage = null;

    /** @var null|string */
    protected $custom_relation_name = null;

    /** @var array */
    protected $tableMap = [];

    /**
     * @param string $sortColumn Column that should be used to update the sort information
     * @param bool $disableSelection Disable selection on the GridField when dragging
     * @param string $updateVersionStage Name of the versioned stage to update this disabled by default unless this is set
     * @param string $customRelationName Name of the relationship to use, if left null the name is determined from the GridField's name
     */
    public function __construct($sortColumn, $disableSelection = true, $updateVersionStage = null, $customRelationName = null)
    {
        $this->sortColumn = $sortColumn;
        $this->disable_selection = $disableSelection;
        $this->update_versioned_stage = $updateVersionStage;
        $this->custom_relation_name = $customRelationName;
    }

    /**
     * Returns a map where the keys are fragment names and the values are pieces of HTML to add to these fragments.
     * @param GridField $gridField Grid Field Reference
     * @return array Map where the keys are fragment names and the values are pieces of HTML to add to these fragments.
     */
    public function getHTMLFragments($gridField)
    {
        $dataList = $gridField->getList();

        if (class_exists('UnsavedRelationList') && $dataList instanceof UnsavedRelationList) {
            return array();
        }

        $state = $gridField->State->GridFieldSortableRows;
        if (!is_bool($state->sortableToggle)) {
            $state->sortableToggle = false;
        }

        //Ensure user can edit
        if (!singleton($gridField->getModelClass())->canEdit()) {
            return array();
        }


        //Sort order toggle
        $sortOrderToggle = GridField_FormAction::create(
            $gridField,
            'sortablerows-toggle',
            'sorttoggle',
            'sortableRowsToggle',
            null
        )->addExtraClass('sortablerows-toggle');


        $sortOrderSave = GridField_FormAction::create(
            $gridField,
            'sortablerows-savesort',
            'savesort',
            'saveGridRowSort',
            null
        )->addExtraClass('sortablerows-savesort');


        //Sort to Page Action
        $sortToPage = GridField_FormAction::create(
            $gridField,
            'sortablerows-sorttopage',
            'sorttopage',
            'sortToPage',
            null
        )->addExtraClass('sortablerows-sorttopage');


        $data = array('SortableToggle' => $sortOrderToggle,
            'SortOrderSave' => $sortOrderSave,
            'SortToPage' => $sortToPage,
            'Checked' => ($state->sortableToggle == true ? ' checked = "checked"' : ''),
            'List' => $dataList);

        $forTemplate = new arrayData($data);

        Requirements::css('undefinedoffset/sortablegridfield:css/GridFieldSortableRows.css');
        Requirements::javascript('undefinedoffset/sortablegridfield:javascript/GridFieldSortableRows.js');

        $args = array('Colspan' => count($gridField->getColumns()), 'ID' => $gridField->ID(), 'DisableSelection' => $this->disable_selection);

        $fragments = array('header' => $forTemplate->renderWith('SortableGridField\Forms\Includes\GridFieldSortableRows', $args));

        if ($gridField->getConfig()->getComponentByType(GridFieldPaginator::class)) {
            $fragments['after'] = $forTemplate->renderWith('SortableGridField\Forms\Includes\GridFieldSortableRows_paginator');
        }

        return $fragments;
    }

    /**
     * Manipulate the datalist as needed by this grid modifier.
     * @param GridField $gridField Grid Field Reference
     * @param SS_List|DataList $dataList Data List to adjust
     * @return DataList Modified Data List
     */
    public function getManipulatedData(GridField $gridField, SS_List $dataList)
    {
        //Detect and correct items with a sort column value of 0 (push to bottom)
        $this->fixSortColumn($gridField, $dataList);


        $headerState = $gridField->State->GridFieldSortableHeader;
        $state = $gridField->State->GridFieldSortableRows;
        if ((!is_bool($state->sortableToggle) || $state->sortableToggle === false) && $headerState && is_string($headerState->SortColumn) && is_string($headerState->SortDirection)) {
            return $dataList->sort($headerState->SortColumn, $headerState->SortDirection);
        }

        if ($state->sortableToggle === true) {
            $gridField->getConfig()->removeComponentsByType(GridFieldFilterHeader::class);
            $gridField->getConfig()->removeComponentsByType(GridFieldSortableHeader::class);
        }

        return $dataList->sort($this->sortColumn);
    }

    /**
     * Sets if new records should be appended to the top or the bottom of the list
     * @param bool $value Boolean true to append to the top false to append to the bottom
     * @return GridFieldSortableRows Returns the current instance
     */
    public function setAppendToTop($value)
    {
        $this->append_to_top = $value;
        return $this;
    }

    /**
     * @param bool $value Boolean true to disable selection of table contents false to enable selection
     * @return GridFieldSortableRows Returns the current instance
     */
    public function setDisableSelection($value)
    {
        $this->disable_selection = $value;
        return $this;
    }

    /**
     * Sets the suffix of the versioned stage that should be updated along side the default stage
     * @param string $value Versioned Stage to update this is disabled by default unless this is set
     * @return GridFieldSortableRows Returns the current instance
     */
    public function setUpdateVersionedStage($value)
    {
        $this->update_versioned_stage = $value;
        return $this;
    }

    /**
     * Sets the name of the relationship to use, by default the name is determined from the GridField's name
     * @param string $value Name of the relationship to use, by default the name is determined from the GridField's name
     * @return GridFieldSortableRows Returns the current instance
     */
    public function setCustomRelationName($value)
    {
        $this->custom_relation_name = $value;
        return $this;
    }

    /**
     * Detects and corrects items with a sort column value of 0, by appending them to the bottom of the list
     * @param GridField $gridField Grid Field Reference
     * @param SS_List|DataList $dataList Data List of items to be checked
     */
    protected function fixSortColumn($gridField, SS_List $dataList)
    {
        if ($dataList instanceof UnsavedRelationList) {
            return;
        }

        /** @var SS_List|DataList $list */
        $list = clone $dataList;
        $list = $list->alterDataQuery(function ($query, SS_List $tmplist) {
            /** @var DataQuery $query */
            $query->limit(array());
            return $query;
        });

        $many_many = ($list instanceof ManyManyList);
        if (!$many_many) {
            $sng = singleton($gridField->getModelClass());
            $fieldType = $sng->config()->db[$this->sortColumn];

            if (!$fieldType || !(strtolower($fieldType) == 'int' || is_subclass_of($fieldType, 'Int'))) {
                if (is_array($fieldType)) {
                    user_error('Sort column ' . $this->sortColumn . ' could not be found in ' . $gridField->getModelClass() . '\'s ancestry', E_USER_ERROR);
                } else {
                    user_error('Sort column ' . $this->sortColumn . ' must be an Int, column is of type ' . $fieldType, E_USER_ERROR);
                }

                exit;
            }
        }

        $max = $list->Max($this->sortColumn);
        $list = $list->filter($this->sortColumn, 0)->sort("Created,ID");
        if ($list->Count() > 0) {
            $owner = $gridField->getForm()->getRecord();
            $sortColumn = $this->sortColumn;
            $i = 1;

            if ($many_many) {
                list($parentClass, $componentClass, $parentField, $componentField, $table) = $owner->many_many((!empty($this->custom_relation_name) ? $this->custom_relation_name : $gridField->getName()));

                /** @var DataObject $table */
                $table = $this->mapTableNameAndReturn($table);

                $extraFields = $owner->many_many_extraFields((!empty($this->custom_relation_name) ? $this->custom_relation_name : $gridField->getName()));

                if (!$extraFields || !array_key_exists($this->sortColumn, $extraFields) || !($extraFields[$this->sortColumn] == 'Int' || is_subclass_of('Int', $extraFields[$this->sortColumn]))) {
                    user_error('Sort column ' . $this->sortColumn . ' must be an Int, column is of type ' . $extraFields[$this->sortColumn], E_USER_ERROR);
                    exit;
                }
            } else {
                //Find table containing the sort column
                $table = false;
                $class = $gridField->getModelClass();

                $db = Config::inst()->get($class, "db", CONFIG::UNINHERITED);
                if (!empty($db) && array_key_exists($sortColumn, $db)) {
                    $table = $this->mapTableNameAndReturn($class);
                } else {
                    $classes = ClassInfo::ancestry($class, true);
                    foreach ($classes as $class) {
                        $db = Config::inst()->get($class, "db", CONFIG::UNINHERITED);
                        if (!empty($db) && array_key_exists($sortColumn, $db)) {
                            $table = $this->mapTableNameAndReturn($class);
                            break;
                        }
                    }
                }

                if ($table === false) {
                    user_error('Sort column ' . $this->sortColumn . ' could not be found in ' . $gridField->getModelClass() . '\'s ancestry', E_USER_ERROR);
                    exit;
                }

                $baseDataClass = DataObject::getSchema()->baseDataClass($gridField->getModelClass());
                $baseDataClass = $this->mapTableNameAndReturn($baseDataClass);

            }


            //Start transaction if supported
            if (DB::get_conn()->supportsTransactions()) {
                DB::get_conn()->transactionStart();
            }

            $idCondition = null;
            if ($this->append_to_top && !($list instanceof RelationList)) {
                $idCondition = '"ID" IN(\'' . implode("','", $dataList->getIDList()) . '\')';
            }

            if ($this->append_to_top) {
                $topIncremented = array();
            }

            foreach ($list as $obj) {
                if ($many_many) {
                    if ($this->append_to_top) {
                        //Upgrade all the records (including the last inserted from 0 to 1)
                        DB::query('UPDATE "' . $table
                            . '" SET "' . $sortColumn . '" = "' . $sortColumn . '"+1'
                            . ' WHERE "' . $parentField . '" = ' . $owner->ID . (!empty($topIncremented) ? ' AND "' . $componentField . '" NOT IN(\'' . implode('\',\'', $topIncremented) . '\')' : ''));

                        $topIncremented[] = $obj->ID;
                    } else {
                        //Append the last record to the bottom
                        DB::query('UPDATE "' . $table
                            . '" SET "' . $sortColumn . '" = ' . ($max + $i)
                            . ' WHERE "' . $componentField . '" = ' . $obj->ID . ' AND "' . $parentField . '" = ' . $owner->ID);
                    }
                } else if ($this->append_to_top) {
                    //Upgrade all the records (including the last inserted from 0 to 1)
                    DB::query('UPDATE "' . $table
                        . '" SET "' . $sortColumn . '" = "' . $sortColumn . '"+1'
                        . ' WHERE ' . ($list instanceof RelationList ? '"' . $list->foreignKey . '" = ' . $owner->ID : $idCondition) . (!empty($topIncremented) ? ' AND "ID" NOT IN(\'' . implode('\',\'', $topIncremented) . '\')' : ''));

                    if ($this->update_versioned_stage && class_exists($this->tableMap[$table]) && $this->hasVersionedExtension($this->tableMap[$table])) {
                        DB::query('UPDATE "' . $table . '_' . $this->update_versioned_stage
                            . '" SET "' . $sortColumn . '" = "' . $sortColumn . '"+1'
                            . ' WHERE ' . ($list instanceof RelationList ? '"' . $list->foreignKey . '" = ' . $owner->ID : $idCondition) . (!empty($topIncremented) ? ' AND "ID" NOT IN(\'' . implode('\',\'', $topIncremented) . '\')' : ''));
                    }

                    $topIncremented[] = $obj->ID;
                } else {
                    //Append the last record to the bottom
                    DB::query('UPDATE "' . $table
                        . '" SET "' . $sortColumn . '" = ' . ($max + $i)
                        . ' WHERE "ID" = ' . $obj->ID);
                    //LastEdited
                    DB::query('UPDATE "' . $baseDataClass
                        . '" SET "LastEdited" = \'' . date('Y-m-d H:i:s') . '\''
                        . ' WHERE "ID" = ' . $obj->ID);

                    if ($this->update_versioned_stage && class_exists($this->tableMap[$table]) && $this->hasVersionedExtension($this->tableMap[$table])) {
                        DB::query('UPDATE "' . $table . '_' . $this->update_versioned_stage
                            . '" SET "' . $sortColumn . '" = ' . ($max + $i)
                            . ' WHERE "ID" = ' . $obj->ID);

                        if ($this->hasVersionedExtension($this->tableMap[$baseDataClass])) {
                            DB::query('UPDATE "' . $baseDataClass . '_' . $this->update_versioned_stage
                                . '" SET "LastEdited" = \'' . date('Y-m-d H:i:s') . '\''
                                . ' WHERE "ID" = ' . $obj->ID);
                        }
                    }
                }

                $i++;
            }

            //Update LastEdited for affected records when using append to top on a many_many relationship
            if (!$many_many && $this->append_to_top && count($topIncremented) > 0) {
                DB::query('UPDATE "' . $baseDataClass
                    . '" SET "LastEdited" = \'' . date('Y-m-d H:i:s') . '\''
                    . ' WHERE "ID" IN(\'' . implode('\',\'', $topIncremented) . '\')');

                if ($this->update_versioned_stage && class_exists($this->tableMap[$table]) && $this->hasVersionedExtension($this->tableMap[$table]) && $this->hasVersionedExtension($this->tableMap[$baseDataClass])) {
                    DB::query('UPDATE "' . $baseDataClass . '_' . $this->update_versioned_stage
                        . '" SET "LastEdited" = \'' . date('Y-m-d H:i:s') . '\''
                        . ' WHERE "ID" IN(\'' . implode('\',\'', $topIncremented) . '\')');
                }
            }


            //End transaction if supported
            if (DB::get_conn()->supportsTransactions()) {
                DB::get_conn()->transactionEnd();
            }
        }
    }

    /**
     * Return a list of the actions handled by this action provider.
     * @param GridField $gridField Grid Field Reference
     * @return array array with action identifier strings.
     */
    public function getActions($gridField)
    {
        return array('saveGridRowSort', 'sortableRowsToggle', 'sortToPage');
    }

    /**
     * Handle an action on the given grid field.
     * @param GridField $gridField Grid Field Reference
     * @param String $actionName Action identifier, see {@link getActions()}.
     * @param array $arguments Arguments relevant for this
     * @param array $data All form data
     */
    public function handleAction(GridField $gridField, $actionName, $arguments, $data)
    {
        $state = $gridField->State->GridFieldSortableRows;
        if (!is_bool($state->sortableToggle)) {
            $state->sortableToggle = false;
        } else if ($state->sortableToggle == true) {
            $gridField->getConfig()->removeComponentsByType(GridFieldFilterHeader::class);
            $gridField->getConfig()->removeComponentsByType(GridFieldSortableHeader::class);
        }


        if ($actionName == 'savegridrowsort') {
            return $this->saveGridRowSort($gridField, $data);
        } else if ($actionName == 'sorttopage') {
            return $this->sortToPage($gridField, $data);
        }
    }

    /**
     * Handles saving of the row sort order
     * @param GridField $gridField Grid Field Reference
     * @param array $data Data submitted in the request
     * @throws ValidationException If user has no edit permissions
     */
    protected function saveGridRowSort(GridField $gridField, $data)
    {
        $dataList = $gridField->getList();

        if ($dataList instanceof UnsavedRelationList) {
            user_error('Cannot sort an UnsavedRelationList', E_USER_ERROR);
            return;
        }

        if (!singleton($gridField->getModelClass())->canEdit()) {
            throw new ValidationException(_t('GridFieldSortableRows.EditPermissionsFailure', "No edit permissions"), 0);
        }

        if (empty($data['ItemIDs'])) {
            user_error('No items to sort', E_USER_ERROR);
        }

        $className = $gridField->getModelClass();
        $owner = $gridField->Form->getRecord();
        $items = clone $gridField->getList();
        $many_many = ($items instanceof ManyManyList);
        $sortColumn = $this->sortColumn;
        $pageOffset = 0;

        if ($paginator = $gridField->getConfig()->getComponentsByType(GridFieldPaginator::class)->First()) {
            $pageState = $gridField->State->GridFieldPaginator;

            if ($pageState->currentPage && is_int($pageState->currentPage) && $pageState->currentPage > 1) {
                $pageOffset = $paginator->getItemsPerPage() * ($pageState->currentPage - 1);
            }
        }


        if ($many_many) {
            list($parentClass, $componentClass, $parentField, $componentField, $table) = $owner->many_many((!empty($this->custom_relation_name) ? $this->custom_relation_name : $gridField->getName()));

            /** @var DataObject $table */
            $table = $this->mapTableNameAndReturn($table);
        } else {
            //Find table containing the sort column
            $table = false;
            $class = $gridField->getModelClass();
            $db = Config::inst()->get($class, "db", CONFIG::UNINHERITED);
            if (!empty($db) && array_key_exists($sortColumn, $db)) {
                $table = $this->mapTableNameAndReturn($class);
            } else {
                $classes = ClassInfo::ancestry($class, true);
                foreach ($classes as $class) {
                    $db = Config::inst()->get($class, "db", CONFIG::UNINHERITED);
                    if (!empty($db) && array_key_exists($sortColumn, $db)) {
                        $table = $this->mapTableNameAndReturn($class);
                        break;
                    }
                }
            }

            if ($table === false) {
                user_error('Sort column ' . $this->sortColumn . ' could not be found in ' . $gridField->getModelClass() . '\'s ancestry', E_USER_ERROR);
                exit;
            }

            $baseDataClass = DataObject::getSchema()->baseDataClass($gridField->getModelClass());
            $baseDataClass = $this->mapTableNameAndReturn($baseDataClass);
        }


        //Event to notify the Controller or owner DataObject before list sort
        if ($owner && $owner instanceof DataObject && method_exists($owner, 'onBeforeGridFieldRowSort')) {
            $owner->onBeforeGridFieldRowSort(clone $items);
        } else if (Controller::has_curr() && Controller::curr() instanceof ModelAdmin && method_exists(Controller::curr(), 'onBeforeGridFieldRowSort')) {
            Controller::curr()->onBeforeGridFieldRowSort(clone $items);
        }

        //Start transaction if supported
        if (DB::get_conn()->supportsTransactions()) {
            DB::get_conn()->transactionStart();
        }

        //Perform sorting
        $ids = explode(',', $data['ItemIDs']);
        for ($sort = 0; $sort < count($ids); $sort++) {
            $id = intval($ids[$sort]);
            if ($many_many) {
                DB::query('UPDATE "' . $table
                    . '" SET "' . $sortColumn . '" = ' . (($sort + 1) + $pageOffset)
                    . ' WHERE "' . $componentField . '" = ' . $id . ' AND "' . $parentField . '" = ' . $owner->ID);
            } else {
                DB::query('UPDATE "' . $table
                    . '" SET "' . $sortColumn . '" = ' . (($sort + 1) + $pageOffset)
                    . ' WHERE "ID" = ' . $id);

                DB::query('UPDATE "' . $baseDataClass
                    . '" SET "LastEdited" = \'' . date('Y-m-d H:i:s') . '\''
                    . ' WHERE "ID" = ' . $id);

                if ($this->update_versioned_stage && class_exists($this->tableMap[$table]) && $this->hasVersionedExtension($this->tableMap[$table])) {
                    DB::query('UPDATE "' . $table . '_' . $this->update_versioned_stage
                        . '" SET "' . $sortColumn . '" = ' . (($sort + 1) + $pageOffset)
                        . ' WHERE "ID" = ' . $id);

                    if ($this->hasVersionedExtension($this->tableMap[$baseDataClass])) {
                        DB::query('UPDATE "' . $baseDataClass . '_' . $this->update_versioned_stage
                            . '" SET "LastEdited" = \'' . date('Y-m-d H:i:s') . '\''
                            . ' WHERE "ID" = ' . $id);
                    }
                }
            }
        }


        //End transaction if supported
        if (DB::get_conn()->supportsTransactions()) {
            DB::get_conn()->transactionEnd();
        }


        //Event to notify the Controller or owner DataObject after list sort
        if ($owner && $owner instanceof DataObject && method_exists($owner, 'onAfterGridFieldRowSort')) {
            $owner->onAfterGridFieldRowSort(clone $items);
        } else if (Controller::has_curr() && Controller::curr() instanceof ModelAdmin && method_exists(Controller::curr(), 'onAfterGridFieldRowSort')) {
            Controller::curr()->onAfterGridFieldRowSort(clone $items);
        }
    }

    /**
     * Handles sorting across pages
     * @param GridField $gridField Grid Field Reference
     * @param array $data Data submitted in the request
     */
    protected function sortToPage(GridField $gridField, $data)
    {
        if (!$paginator = $gridField->getConfig()->getComponentsByType(GridFieldPaginator::class)->First()) {
            user_error('Paginator not detected', E_USER_ERROR);
        }

        if (empty($data['ItemID'])) {
            user_error('No item to sort', E_USER_ERROR);
        }

        if (empty($data['Target'])) {
            user_error('No target page', E_USER_ERROR);
        }

        /** @var Extensible $className */
        $className = $gridField->getModelClass();
        $owner = $gridField->Form->getRecord();

        /** @var DataList $items */
        $items = clone $gridField->getList();

        $many_many = ($items instanceof ManyManyList);
        $sortColumn = $this->sortColumn;
        $targetItem = $items->byID(intval($data['ItemID']));

        if (!$targetItem) {
            user_error('Target item not found', E_USER_ERROR);
        }

        $currentPage = 1;

        $pageState = $gridField->State->GridFieldPaginator;
        if ($pageState->currentPage && $pageState->currentPage > 1) {
            $currentPage = $pageState->currentPage;
        }

        if ($many_many) {
            list($parentClass, $componentClass, $parentField, $componentField, $table) = $owner->many_many((!empty($this->custom_relation_name) ? $this->custom_relation_name : $gridField->getName()));
            $table = $this->mapTableNameAndReturn($table);
        }

        if ($data['Target'] == 'previouspage') {
            $items = $items->limit($paginator->getItemsPerPage() + 1, ($paginator->getItemsPerPage() * ($currentPage - 1)) - 1);
        } else if ($data['Target'] == 'nextpage') {
            $items = $items->limit($paginator->getItemsPerPage() + 1, $paginator->getItemsPerPage() * ($currentPage - 1));
        } else {
            user_error('Not implemented: ' . $data['Target'], E_USER_ERROR);
        }

        $sortPositions = $items->column($sortColumn);

        //Event to notify the Controller or owner DataObject before list sort
        if ($owner && $owner instanceof DataObject && method_exists($owner, 'onBeforeGridFieldPageSort')) {
            $owner->onBeforeGridFieldPageSort(clone $items);
        } else if (Controller::has_curr() && Controller::curr() instanceof ModelAdmin && method_exists(Controller::curr(), 'onBeforeGridFieldPageSort')) {
            Controller::curr()->onBeforeGridFieldPageSort(clone $items);
        }

        //Find the sort column
        if ($this->update_versioned_stage && $this->hasVersionedExtension($className)) {
            $table = false;
            $classes = ClassInfo::ancestry($className, true);
            foreach ($classes as $class) {
                $db = Config::inst()->get($class, "db", CONFIG::UNINHERITED);
                if (!empty($db) && array_key_exists($sortColumn, $db)) {
                    $table = $this->mapTableNameAndReturn($class);
                    break;
                }
            }

            if ($table === false) {
                user_error('Sort column ' . $this->sortColumn . ' could not be found in ' . $gridField->getModelClass() . '\'s ancestry', E_USER_ERROR);
                exit;
            }
        }

        //Start transaction if supported
        if (DB::get_conn()->supportsTransactions()) {
            DB::get_conn()->transactionStart();
        }

        if ($data['Target'] == 'previouspage') {
            if ($many_many) {
                DB::query('UPDATE "' . $table
                    . '" SET "' . $sortColumn . '" = ' . $sortPositions[0]
                    . ' WHERE "' . $componentField . '" = ' . $targetItem->ID . ' AND "' . $parentField . '" = ' . $owner->ID);
            } else {
                $targetItem->$sortColumn = $sortPositions[0];
                $targetItem->write();

                if ($this->update_versioned_stage && $this->hasVersionedExtension($className)) {
                    DB::query('UPDATE "' . $table . '_' . $this->update_versioned_stage
                        . '" SET "' . $sortColumn . '" = ' . $sortPositions[0]
                        . ' WHERE "ID" = ' . $targetItem->ID);
                }
            }

            $i = 1;
            foreach ($items as $obj) {
                if ($obj->ID == $targetItem->ID) {
                    continue;
                }


                if ($many_many) {
                    DB::query('UPDATE "' . $table
                        . '" SET "' . $sortColumn . '" = ' . $sortPositions[$i]
                        . ' WHERE "' . $componentField . '" = ' . $obj->ID . ' AND "' . $parentField . '" = ' . $owner->ID);
                } else {
                    $obj->$sortColumn = $sortPositions[$i];
                    $obj->write();

                    if ($this->update_versioned_stage && $this->hasVersionedExtension($className)) {
                        DB::query('UPDATE "' . $table . '_' . $this->update_versioned_stage
                            . '" SET "' . $sortColumn . '" = ' . $sortPositions[$i]
                            . ' WHERE "ID" = ' . $obj->ID);
                    }
                }

                $i++;
            }
        } else {
            if ($many_many) {
                DB::query('UPDATE "' . $table
                    . '" SET "' . $sortColumn . '" = ' . $sortPositions[count($sortPositions) - 1]
                    . ' WHERE "' . $componentField . '" = ' . $targetItem->ID . ' AND "' . $parentField . '" = ' . $owner->ID);
            } else {
                $targetItem->$sortColumn = $sortPositions[count($sortPositions) - 1];
                $targetItem->write();

                if ($this->update_versioned_stage && $this->hasVersionedExtension($className)) {
                    DB::query('UPDATE "' . $table . '_' . $this->update_versioned_stage
                        . '" SET "' . $sortColumn . '" = ' . $sortPositions[count($sortPositions) - 1]
                        . ' WHERE "ID" = ' . $targetItem->ID);
                }
            }

            $i = 0;
            foreach ($items as $obj) {
                if ($obj->ID == $targetItem->ID) {
                    continue;
                }


                if ($many_many) {
                    DB::query('UPDATE "' . $table
                        . '" SET "' . $sortColumn . '" = ' . $sortPositions[$i]
                        . ' WHERE "' . $componentField . '" = ' . $obj->ID . ' AND "' . $parentField . '" = ' . $owner->ID);
                } else {
                    $obj->$sortColumn = $sortPositions[$i];
                    $obj->write();

                    if ($this->update_versioned_stage && $this->hasVersionedExtension($className)) {
                        DB::query('UPDATE "' . $table . '_' . $this->update_versioned_stage
                            . '" SET "' . $sortColumn . '" = ' . $sortPositions[$i]
                            . ' WHERE "ID" = ' . $obj->ID);
                    }
                }

                $i++;
            }
        }

        //End transaction if supported
        if (DB::get_conn()->supportsTransactions()) {
            DB::get_conn()->transactionEnd();
        }

        //Event to notify the Controller or owner DataObject after list sort
        if ($owner && $owner instanceof DataObject && method_exists($owner, 'onAfterGridFieldPageSort')) {
            $owner->onAfterGridFieldPageSort(clone $items);
        } else if (Controller::has_curr() && Controller::curr() instanceof ModelAdmin && method_exists(Controller::curr(), 'onAfterGridFieldPageSort')) {
            Controller::curr()->onAfterGridFieldPageSort(clone $items);
        }
    }

    /**
     * Check to see if the given class name has the Versioned extension
     *
     * @param Extensible|string $className
     * @return bool
     */
    public function hasVersionedExtension($className)
    {
        return $className::has_extension(Versioned::class);
    }

    /**
     * Checks to see if $table_name is declared on the DataObject, if not returns string as given
     *
     * @param $tableName
     * @return mixed
     */
    public function mapTableNameAndReturn($tableName)
    {
        if (array_key_exists($tableName, $this->tableMap)) {
            return $this->tableMap[$tableName];
        }

        $realName = (Config::inst()->get($tableName, 'table_name', CONFIG::UNINHERITED)) ? Config::inst()->get($tableName, 'table_name', CONFIG::UNINHERITED) : $tableName;

        $this->tableMap[$realName] = $tableName;

        return $realName;
    }
}
