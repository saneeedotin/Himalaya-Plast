Sample files for `php tools/import_csv.php <customers|suppliers|items> <file> [--commit] [--update]`.
The first row is the header; the order of columns does not matter. Comma or semicolon separated (Excel for your region), with or without a byte-order mark.
Required columns are marked in the help at the top of `hpos-php/tools/import_csv.php`.
Run without `--commit` first: it checks every row and prints the line number and reason for anything it would reject.
