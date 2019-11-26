<?php

namespace CatchDesign\SSBGExportImport;

use ClassInfo;


class ExportImportUtils extends Object {

    /**
     * static cache for class fields
     * @var Arrary
     */
    protected static $f_list = [];

    /**
     * static cache for class relations
     * @var Arrary
     */
    protected static $r_list = [];

    /**
     * static cache for class info
     * @var Arrary
     */
    protected static $c_list = [];

    /**
     * Returns an array of subclasses of DataObject
     * @return Array
     */
    public static function data_classes() {
        if (empty(static::$c_list)) {
            $raw = ClassInfo::subclassesFor('DataObject');
            sort($raw);
            static::$c_list = $raw;
        }
        return static::$c_list;
    }

    /**
     * Returns an associative array of subclasses of DataObject for a dropdown list
     * @return Array
     */
    public static function data_classes_for_dd() {
        $classes = static::data_classes();
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
    public static function local_fields($className) {

        // build the f_list if we need it
        if (empty(static::$f_list[$className])) {

            // build field list
            $cls = $className;
            $fields = ['ID' => ''];
            while ($cls != 'ViewableData') {
                $fields = array_merge($fields, $className::database_fields($cls));
                $cls = get_parent_class($cls);
            }
            static::$f_list[$className] = $fields;
        }

        // return $f_list
        return static::$f_list[$className];
    }

    /**
     * return the list of fields to look at
     * @param  String $className [description]
     * @return Array            [description]
     */
    public static function related_fields($className) {

        // build the r_list if we need it
        if (empty(static::$r_list[$className])) {

            // build field list
            $cls = $className;
            $hasOne = [];
            $hasMany = [];
            $manyMany = [];
            while ($cls != 'ViewableData') {

                // get the static data
                $nHasOne = singleton($cls)->stat('has_one');
                $nHasMany = singleton($cls)->stat('has_many');
                $nManyMany = singleton($cls)->stat('many_many');

                // merge
                $hasOne = array_merge($hasOne, is_array($nHasOne) ? $nHasOne : []);
                $hasMany = array_merge($hasMany, is_array($nHasMany) ? $nHasMany : []);
                $manyMany = array_merge($manyMany, is_array($nManyMany) ? $nManyMany : []);
                $cls = get_parent_class($cls);
            }
            static::$r_list[$className] = [
                'has_one' => $hasOne,
                'has_many' => $hasMany,
                'many_many' => $manyMany
            ];
        }

        // return $r_list
        return static::$r_list[$className];
    }

    /**
     * all the data fields
     * @param  String $className [description]
     * @return Array            [description]
     */
    public static function all_fields($className) {
        $fields = static::related_fields($className);
        $fields['db'] = static::local_fields($className);
        return $fields;
    }
}
