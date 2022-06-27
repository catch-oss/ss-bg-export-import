<?php

namespace CatchDesign\SSBGExportImport;

use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\ClassInfo;
use SilverStripe\View\ViewableData;

class ExportImportUtils {

    use Extensible;
    use Injectable;
    use Configurable;

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
            $raw = ClassInfo::subclassesFor(DataObject::class);
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
            $schema = DataObject::getSchema();
            $cls = $className;
            $fields = ['ID' => ''];
            while ($cls != ViewableData::class) {
                $fields = array_merge($fields, $className::database_fields($schema->fieldSpecs($cls)));
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
            $belongs = [];
            $hasOne = [];
            $hasMany = [];
            $manyMany = [];
            $belongsManyMany = [];
            while ($cls != ViewableData::class) {

                // get the static data
                $nBelongs = singleton($cls)->stat('belongs');
                $nHasOne = singleton($cls)->stat('has_one');
                $nHasMany = singleton($cls)->stat('has_many');
                $nManyMany = singleton($cls)->stat('many_many');
                $nBelongsManyMany = singleton($cls)->stat('belongs_many_many');

                // merge
                $belongs = array_merge($belongs, is_array($nBelongs) ? $nBelongs : []);
                $hasOne = array_merge($hasOne, is_array($nHasOne) ? $nHasOne : []);
                $hasMany = array_merge($hasMany, is_array($nHasMany) ? $nHasMany : []);
                $manyMany = array_merge($manyMany, is_array($nManyMany) ? $nManyMany : []);
                $belongsManyMany = array_merge($belongsManyMany, is_array($nBelongsManyMany) ? $nBelongsManyMany : []);
                $cls = get_parent_class($cls);
            }
            static::$r_list[$className] = [
                'belongs' => $belongs,
                'has_one' => $hasOne,
                'has_many' => $hasMany,
                'many_many' => $manyMany,
                'belongs_many_many' => $belongsManyMany
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
