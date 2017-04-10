SS BG Export / Import
=====================

Creates a model admin that can be used to export and import bulk data records
on a class by class basis

## Why?

1. Once you hit ~10 000 records SS chokes and can't export anything due to the
length of time it takes to create the CSV
2. You only get the output of the Summary fields on the DataObject so it was never
suitable for complete export / import

## Draft issue

Some items expect (or you want) them to go in to draft.
An issue can occur when writing pages in draft as they get placed in the Root page.
They are then not detected as 'allowed children' of the root page. Choose "Skip Draft" to get around this issue


## todo

- when generating field list
--> get fields for class and all subclasses
--> validate fields during other operations
- tighten up premissions - most likely i had weird users / groups so i used 777 :-0
