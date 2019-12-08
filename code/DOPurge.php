<?php

namespace CatchDesign\SSBGExportImport;

use Exception;

use SilverStripe\Versioned\Versioned;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\Security\Member;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataList;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;

class DOPurge extends DataObject implements PermissionProvider {

    private static $db = array(
        'PurgeClass'        => 'Varchar(255)',
        'Filter'            => 'Text',
        'Info'              => 'Text',
        'Status'            => 'Enum(\'new,processing,processed\',\'new\')',
        'Success'           => DBBoolean::class,
        'JobSize'           => 'Int',
        'JobProgress'       => 'Int',
        'JobMemoryUse'      => 'Int'
    );

    private static $has_one = array(
        'Member'            => Member::class,
    );

    private static $summary_fields = array(
        'Status',
        'MemberName',
        'JobSize',
        'JobProgress',
        'Created',
        'LastEdited',
    );

    private static $default_sort = 'Created DESC';

    public function getCMSFields() {
        $fields = parent::getCMSFields();

        // set up the fields for new records
        if (!$this->ID) {

            $fields->removeByName('Info');
            $fields->removeByName('Status');
            $fields->removeByName('Success');
            $fields->removeByName('MemberID');
            $fields->removeByName('JobSize');
            $fields->removeByName('JobProgress');
            $fields->removeByName('JobMemoryUse');

            $fields->addFieldsToTab(
                'Root.Main',
                [
                    new DropdownField('PurgeClass', 'PurgeClass', ExportImportUtils::data_classes_for_dd()),
                    new TextareaField('Filter', 'Filter SQL (Optional)'),
                ]
            );
        }

        return $fields;
    }

    public function onBeforeWrite() {

        parent::onBeforeWrite();

        // save this for audit purposes
        if (!$this->MemberID)
            if ($user = Member::currentUser())
                $this->MemberID = $user->ID;
    }

    /**
     * Processes the Purge
     * @return [type] [description]
     */
    public function process() {

        // eol
        $eol = php_sapi_name() == 'cli' ? "\n" : '<br>';
        $start = time();

        // we don't want to try this more than once
        if ($this->Status == 'new') {

            // 1GiB is good for ~100k records so a record consumes ~10kiB
            // We'll play it safe and call it 20kiB which should offer some headroom
            // i.e. 1GiB = 50k records per file
            // -> need to chunk the Export

            // Mem Limit
            $memLimit = ini_get('memory_limit');
            if ($memLimit == '-1') $memLimit = '1G';

            // parse to MiBs
            $memLimitMiB = preg_replace('/[^0-9]+/', '', $memLimit);

            // correct for G values
            if (preg_match('/G/', $memLimit)) $memLimitMiB *= 1024;

            // calc chunk size - allow for ~10kiB / Record
            $chunkSize = floor((($memLimitMiB / 1024) * 1000) / (1 + 1)) * 50;

            // Let everyone know this is being processed
            $this->Status = 'processing';
            $this->write();

            // helpful output
            echo 'Processing Purge #' . $this->ID . $eol;
            echo 'Memory Limit ' . $memLimitMiB . 'MiB | Chunk Size: ' . $chunkSize . $eol;

            // try to get the package
            try {

                // get some versioning info
                $isVersioned = (
                    singleton($this->PurgeClass)->hasExtension('Versioned') ||
                    singleton($this->PurgeClass)->hasExtension('VersionedDataObject')
                );
                $stages = ['Stage', 'Live'];

                // treat versioned  classes differently
                if ($isVersioned) {

                    foreach ($stages as $stage) {

                        // make a list
                        $list = Versioned::get_by_stage($this->PurgeClass, $stage);

                        // do we want to filter them
                        if ($this->Filter) $list = $list->where($this->Filter);

                        // count the full length
                        $this->JobSize += $list->count();

                        // helpful output
                        echo 'Processing ' . $this->JobSize . ' total root level records in query ' . $eol;

                        // set the chunk
                        $chunk = 0;
                        $list = $list->limit($chunkSize, $chunk * $chunkSize);
                        $curListLen = $list->count();

                        // update record
                        $this->JobMemoryUse = memory_get_peak_usage(true);
                        $this->write();

                        // set up loop
                        while ($curListLen) {

                            // helpful output
                            echo 'Processing ' . $curListLen . ' root level records in chunk ' . $chunk . $eol;
                            echo 'Mem Usage ' . memory_get_usage(true) . 'B' . $eol;
                            echo 'Time elapsed: ' . (time() - $start) . 's' . $eol;

                            // delete stuff
                            foreach ($list as $item) {

                                // oddly some things dont have IDs?
                                if ($item->ID) $item->deleteFromStage($stage);
                                if ($item->ID) $item->delete();

                                // update the progress
                                $this->JobProgress++;
                                $this->JobMemoryUse = memory_get_peak_usage(true);
                                $this->write();
                            }

                            // free some RAM
                            $mem = memory_get_usage();
                            DataObject::flush_and_destroy_cache();
                            DataObject::reset();
                            gc_collect_cycles();
                            $freed = $mem - memory_get_usage();

                            // helpful output
                            echo 'Freed ' . $freed . 'B Memory' . $eol;

                            // set the chunk
                            $chunk++;
                            $list = $list->limit($chunkSize, $chunk * $chunkSize);
                            $curListLen = $list->count();
                        }
                    }
                }
                else {

                    // make a list
                    $list = new DataList($this->PurgeClass);

                    // do we want to filter them
                    if ($this->Filter) $list = $list->where($this->Filter);

                    // count the full length
                    $this->JobSize += $list->count();

                    // helpful output
                    echo 'Processing ' . $this->JobSize . ' total root level records in query ' . $eol;

                    // set the chunk
                    $chunk = 0;
                    $list = $list->limit($chunkSize, $chunk * $chunkSize);
                    $curListLen = $list->count();

                    // update record
                    $this->JobMemoryUse = memory_get_peak_usage(true);
                    $this->write();

                    // set up loop
                    while ($curListLen) {

                        // helpful output
                        echo 'Processing ' . $curListLen . ' root level records in chunk ' . $chunk . $eol;
                        echo 'Mem Usage ' . memory_get_usage(true) . 'B' . $eol;
                        echo 'Time elapsed: ' . (time() - $start) . 's' . $eol;

                        // delete stuff
                        foreach ($list as $item) {

                            // oddly some things dont have IDs?
                                if ($item->ID) $item->delete();

                            // update the progress
                            $this->JobProgress++;
                            $this->JobMemoryUse = memory_get_peak_usage(true);
                            $this->write();
                        }

                        // free some RAM
                        $mem = memory_get_usage();
                        DataObject::flush_and_destroy_cache();
                        DataObject::reset();
                        gc_collect_cycles();
                        $freed = $mem - memory_get_usage();

                        // helpful output
                        echo 'Freed ' . $freed . 'B Memory' . $eol;

                        // set the chunk
                        $chunk++;
                        $list = $list->limit($chunkSize);
                        $curListLen = $list->count();
                    }
                }

                // update the record
                $this->Status = 'processed';
                $this->JobMemoryUse = memory_get_peak_usage(true);
                $this->Success = true;
                $this->write();
            }

            // something went wrong
            catch (Exception $e) {

                // deliver the bad news
                $this->Status = 'processed';
                $this->JobMemoryUse = memory_get_peak_usage(true);
                $this->Info .= $e->getMessage();
                $this->write();
            }
        }
    }

    /**
     * create the permissions required
     * @return array
     */
    public function providePermissions() {
        return array(
            "ACCESS_DO_PURGE" => "Access Data Object Purge Utility"
        );
    }

    public function canCreate($member = false, $context = array()) {
        return Permission::check('ACCESS_DO_PURGE');
    }

    public function canView($member = false) {
        return Permission::check('ACCESS_DO_PURGE');
    }

    public function canEdit($member = false) {
        return $this->ID ? false : Permission::check('ACCESS_DO_PURGE');
    }

    public function canDelete($member = false) {
        return false;
    }

    public function StatusMessage() {
        if ($this->Status == 'new')
            return 'Scheduled - should begin processing within 2 minutes';

        if ($this->Status == 'processing')
            return 'Purge in progress';

       if ($this->Status == 'processed')
           return $this->Success ? 'Purge Complete' : 'Purge Failed';
    }

    public function MemberName() {

        if ($user = $this->Member())
            if ($user->ID)
                return $user->FirstName . ' ' . $user->Surname . '(#' . $user->ID . ')';

        return null;
    }
}
