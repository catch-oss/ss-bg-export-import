<?php

class ExportImportUtils extends Object {

    protected static $f_list = [];

    /**
     * Returns an array of subclasses of DataObject
     * @return Array
     */
    public static function DataClasses() {
        return ClassInfo::subclassesFor('DataObject');
    }

    /**
     * Returns an associative array of subclasses of DataObject for a dropdown list
     * @return Array
     */
    public static function DataClassesForDD() {
        $classes = static::DataClasses();
        $list = array();
        foreach ($classes as $class) {
            $list[$class] = $class;
        }
        return $list;
    }

    /**
     * return the list of fields to look at
     * @param  [type] $className [description]
     * @return [type]            [description]
     */
    public static function exportFields($className) {

        // build the f_list if we need it
        if (empty(static::$f_list[$className])) {

            // build field list
            $cls = $className;
            $fields = ['ID' => ''];
            while ($cls != 'ViewableData') {
                $fields = array_merge($fields, static::database_fields($cls));
                $cls = get_parent_class($cls);
            }
            static::$f_list[$className] = $fields;
        }

        // populate vals
        $out = [];
        foreach (static::$f_list[$className] as $f => $conf) {
            $out[$f] = $this->$f;
        }

        // return $f_list
        return $out;
    }
}
