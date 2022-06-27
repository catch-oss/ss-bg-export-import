<?php

namespace CatchDesign\SSBGExportImport;

use Exception;
use SilverStripe\ORM\FieldType\DBBoolean;
use SilverStripe\Security\Member;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataList;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use CatchDesign\SSBGExportImport\ExportImportutils;

class DOExport extends DataObject implements PermissionProvider {


    /**
     * @var string
     */
    static private $apacheUser = '';

    private static $table_name = 'DOExport';

    private static $db = array(
        'ExportClass'   => 'Varchar(255)',
        'FilePath'      => 'Varchar(255)',
        'Filter'        => 'Text',
        'Format'        => 'Enum(\'CSV,JSON,TXT\',\'CSV\')',
        'Depth'         => 'Int',
        'JobSize'       => 'Int',
        'JobProgress'   => 'Int',
        'JobMemoryUse'  => 'Int',
        'Info'          => 'Text',
        'Status'        => 'Enum(\'new,processing,processed\',\'new\')',
        'Success'       => DBBoolean::class,
    );

    private static $has_one = array(
        'Member'    => Member::class
    );

    private static $summary_fields = array(
        'ExportClass',
        'Format',
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
            $fields->removeByName('FilePath');
            $fields->removeByName('JobSize');
            $fields->removeByName('JobProgress');
            $fields->removeByName('JobMemoryUse');

            $fields->addFieldsToTab(
                'Root.Main',
                [
                    new DropdownField('ExportClass', 'Export Class', ExportImportUtils::data_classes_for_dd()),
                    new DropdownField('Depth', 'Export Related Data to Depth', [
                        '0' => 'Don\'t export related data',
                        '1' => '1',
                        '2' => '2',
                        '3' => '3',
                        '4' => '4',
                        '5' => '5',
                        '6' => '6',
                        '7' => '7',
                        '8' => '8',
                        '9' => '9',
                    ]),
                    new TextareaField('Filter', 'Filter SQL (Optional)'),
                ]
            );
        }

        else {

            // display the link if it's apropriate
            if ($this->Status == 'processed' && $this->Success) {
                $fields->addFieldToTab(
                    'Root.Main',
                    new LiteralField('FilePath', '<p class="field"><a href="/ExportFileExporter/export/' . $this->ID . '">Download Export File</a></p>')
                );
            }

            // don't display things if we dont need to
            else {
                $fields->removeByName('FilePath');
            }
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

    protected function extractData($obj, $depth = 0) {

        // get the fields we are exporting
        $fields = ExportImportutils::all_fields($obj->ClassName ?: get_class($obj));

        // init the collector
        $out = [];

        // loop the fields
        foreach ($fields as $type => $fData) {

            // always export the local columns
            if ($type == 'db') {
                foreach ($fData as $name => $conf) {
                    $out[$name] = $obj->$name;
                }
            }

            // did we want to include any related columns
            else if ((int) $this->Depth > $depth) {

                // loop through the field data
                foreach ($fData as $name => $conf) {

                    // get the relation data
                    $rel = $obj->$name();

                    // is it a to many?
                    if (!is_a($rel, DataObject::class)) {

                        // init the collector
                        $out[$name] = [];

                        // accumulate items
                        foreach ($rel as $item) {
                            $out[$name][] = !empty($item->ID)
                                ? $this->extractData($item, $depth + 1)
                                : null;
                        }
                    }

                    // it's a to one
                    else {
                        $out[$name] = !empty($rel->ID)
                            ? $this->extractData($rel, $depth + 1)
                            : null;
                    }
                }
            }
        }

        // deliver the package
        return $out;
    }

    /**
     * Processes the Export
     * at the end of each chunk we need to free as much RAM as possible
     * download will need to zip the files, free RAM then, do a passthru serve:
     * http://stackoverflow.com/questions/6914912/streaming-a-large-file-using-php
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
            $chunkSize = floor((($memLimitMiB / 1024) * 1000) / (1 + $this->Depth)) * 50;

            // Let everyone know this is being processed
            $this->Status = 'processing';
            $this->write();

            // helpful output
            echo 'Processing Export #' . $this->ID . $eol;
            echo 'Memory Limit ' . $memLimitMiB . 'MiB | Chunk Size: ' . $chunkSize . $eol;

            // try to get the package
            try {

                // get the source data
                $list = new DataList($this->ExportClass);

                // are we filtering
                if ($this->Filter) $list = $list->where($this->Filter);

                // get the fields we are exporting
                $fields = ExportImportutils::all_fields($this->ExportClass);

                // count the full length
                $this->JobSize = $list->count();

                // helpful output
                echo 'Processing ' . $this->JobSize . ' total root level records in query ' . $eol;

                // set the chunk
                $chunk = 0;
                $list = $list->limit($chunkSize, $chunk * $chunkSize);
                $curListLen = $list->count();

                // set up loop
                while ($curListLen) {

                    // init the collector
                    $out = [];

                    // update record
                    $this->JobMemoryUse = memory_get_peak_usage(true);
                    $this->write();

                    // helpful output
                    echo 'Processing ' . $curListLen . ' root level records in chunk ' . $chunk . $eol;
                    echo 'Mem Usage ' . memory_get_usage(true) . 'B' . $eol;
                    echo 'Time elapsed: ' . (time() - $start) . 's' . $eol;

                    // loop the loop
                    foreach ($list as $item) {

                        // extract the data from the object
                        $data = $this->extractData($item);

                        // init the row recievers
                        $row = [];
                        $hRow = [];

                        // loop the fields
                        foreach ($fields as $type => $fData) {

                            // only CSV reqires a header row
                            if ($this->Format == 'CSV') {

                                // do we need to create header row
                                if (empty($out)) {

                                    // always export the local column headings
                                    if ($type == 'db') {
                                        foreach ($fData as $name => $conf) {
                                            $hRow[] = $name;
                                        }
                                    }

                                    // did we want to include any related column headings
                                    else if ((int) $this->Depth > 0) {
                                        foreach ($fData as $name => $conf) {
                                            $hRow[] = $name;
                                        }
                                    }
                                }
                            }

                            // always export the local columns
                            if ($type == 'db') {
                                foreach ($fData as $name => $conf) {
                                    if ($this->Format == 'CSV') $row[] = $data[$name];
                                    else $row[$name] = $data[$name];
                                }
                            }

                            // did we want to include any related columns
                            else if ((int) $this->Depth > 0) {
                                foreach ($fData as $name => $conf) {

                                    // Serialised TXT and JSON are nested
                                    // so they don't need to be transformed at this point
                                    $this->Format == 'CSV'
                                        ? $row[] = json_encode($data[$name])
                                        : $row[$name] = $data[$name];
                                }
                            }
                        }

                        // append header row
                        if (!empty($hRow)) $out[] = $hRow;

                        // append the datas
                        $out[] = $row;

                        // update the progress
                        $this->JobProgress++;
                        $this->JobMemoryUse = memory_get_peak_usage(true);
                        $this->write();
                    }

                    // what CSV are we looking at here
                    $dir = 'data-exports';
                    $fPath = $dir . '/' . $this->ID . '-' . $chunk . '.' . strtolower($this->Format);
                    $fullPath = ASSETS_PATH . '/' . $fPath;

                    // make sure the dir exists
                    if (!is_dir(ASSETS_PATH . '/' . $dir)) {
                        mkdir(ASSETS_PATH . '/' . $dir, 0775, true);
                    }

                    // ensure permissions are set correctly
                    $this->setPermissions(ASSETS_PATH . '/' . $dir);

                    // make sure there's an htaccess file blocking access
                    file_put_contents(ASSETS_PATH . '/' . $dir . '/.htaccess', 'Require all denied');

                    // write the file
                    switch ($this->Format) {

                        case 'TXT':
                            file_put_contents($fullPath, serialize($out));
                            break;

                        case 'JSON':
                            file_put_contents($fullPath, json_encode($out));
                            break;

                        case 'CSV':
                            $fp = fopen($fullPath, 'w');
                            foreach ($out as $line) fputcsv($fp, $line);
                            fclose($fp);
                            break;
                    }

                    // ensure permissions are set correctly
                    $this->setPermissions($fullPath);

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

                // yay
                $this->Status = 'processed';
                $this->JobMemoryUse = memory_get_peak_usage(true);
                $this->FilePath = $dir;
                $this->Success = true;
                $this->write();

            }

            // something went wrong
            catch (Exception $e) {

                echo $e->getMessage();

                // deliver the bad news
                $this->Status = 'processed';
                $this->JobMemoryUse = memory_get_peak_usage(true);
                $this->Info = $e->getMessage();
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
            "ACCESS_DO_EXPORT" => "Access Data Object Export Utility"
        );
    }

    public function canCreate($member = false, $context = array()) {
        return Permission::check('ACCESS_DO_EXPORT');
    }

    public function canView($member = false) {
        return Permission::check('ACCESS_DO_EXPORT');
    }

    public function canEdit($member = false) {
        return $this->ID ? false : Permission::check('ACCESS_DO_EXPORT');
    }

    public function canDelete($member = false) {
        return false;
    }

    public function StatusMessage() {
        if ($this->Status == 'new')
            return 'Scheduled - should begin processing within 2 minutes';

        if ($this->Status == 'processing')
            return 'Export in progress';

       if ($this->Status == 'processed')
           return $this->Success ? 'Export Complete' : 'Export Failed';
    }

    public function MemberName() {

        if ($user = $this->Member())
            if ($user->ID)
                return $user->FirstName . ' ' . $user->Surname . '(#' . $user->ID . ')';

        return null;
    }

    /**
     * Set permissions on the underlying file of an SS file ref
     *
     * @param string $path
     * @return boolean
     */
    protected function setPermissions(string $path): bool
    {
        $chmodResult = chmod($path, 0775);
        $chownResult = chown($path, $this->getApacheUser());
        return $chmodResult && $chownResult;
    }

    /**
     * Find the user apache is running as
     *
     * @return string
     */
    protected function getApacheUser(): string
    {
        // check static cache
        if (!self::$apacheUser) {

            // determine apache user
            if (stripos(PHP_OS, 'darwin') !== false) {
                $apacheUser = trim(exec("ps aux | grep -v root | grep '[h]ttpd' | cut -d\\  -f1 | sort | uniq"));
                $apacheUser = $apacheUser ?: '_www';
            } else {
                $apacheUser = 'www-data';
            }

            // populate static cache
            self::$apacheUser = $apacheUser;
        }

        // return the value from the static cache
        return self::$apacheUser;
    }
}
