<?php 
/**
 * PHP Library to read and edit a .srr file.
 * Copyright (c) 2011-2012 pyReScene
 *
 * rescene.php is free software, you can redistribute it and/or modify
 * it under the terms of GNU Affero General Public License
 * as published by the Free Software Foundation, either version 3
 * of the License, or (at your option) any later version.
 * 
 * You should have received a copy of the the GNU Affero
 * General Public License, along with rescene.php. If not, see
 * http://www.gnu.org/licenses/agpl.html
 * 
 * Additional permission under the GNU Affero GPL version 3 section 7:
 * 
 * If you modify this Program, or any covered work, by linking or
 * combining it with other code, such other code is not for that reason
 * alone subject to any of the requirements of the GNU Affero GPL
 * version 3.
 */

/*
 * LGPLv3 with Affero clause (LAGPL)
 * See http://mo.morsi.org/blog/node/270
 * rescene.php written on 2011-07-27
 * Last version: 2012-09-12
 * 
 * Features:
 *  - process a SRR file which returns:
 *     - SRR file size.
 *     - Application name of the tool used to create the SRR file.
 *     - List of files stored in the SRR.
 *     - List of RAR volumes the SRR can reconstruct.
 *     - List of files that are archived inside these RARs.
 *     - Size of all Recovery Records inside the SRR file.
 *     - Comments inside SFV files.
 *     - Warnings when something unusual is found with the SRR.
 *  - Remove a stored file.
 *  - Rename a stored file.
 *  - Add a stored file.
 *  - Read a stored file.
 *  - Extract a stored file.
 *  - Calculate a hash of the SRR based on RAR metadata.
 *  - Sorting of the stored file names.
 *  - process in memory SRR 'file'
 *  - compare two SRR files
 *      - nfo: ignore line endings
 *      - sfv: sort it before comparing and remove comment lines
 *      - rar metadata
 *          -> quick: by hash
 *          -> see what is missing
 *      - other files
 *          -> quick: by hash
 *  - Output flag added to indicate if the RARs used compression.
 *  - Support to read SRS files. (AVI/MKV)
 *  
 *  - nfo compare: strip line endings + new line?
 *      Indiana.Jones.And.The.Last.Crusade.1989.PAL.DVDR-DNA
 *
 * List of possible features/todo list:
 *  - process in memory SRR 'file' + other API functions (very low priority)
 *      => can be done using temp files in memory
 *  - compare SRS files
 *  - refactor compare SRR
 *  - merge SRRs (Python script exists)
 *  - encryption sanity check
 *  - add paths before the rar files
 *  - detect when SRR is cut/metadata from rars missing
 *      => hard to do correctly (SFVs subs exist too)
 *  - how to throw errors correctly?
 *  - sorting the list of the stored files by hand
 *  - make it impossible to rename to an existing file
 *  - "Application name found in the middle of the SRR."
 *    causes hashes to be different
 * 
 */ 


$BLOCKNAME = array(
     0x69 => 'SRR VolumeHeader',
     0x6A => 'SRR Stored File',
     0x71 => 'SRR RAR subblock',
     0x72 => 'RAR Marker',
     0x73 => 'Archive Header',
     0x74 => 'File',
     0x75 => 'Old style - Comment',
     0x76 => 'Old style - Extra info (authenticity information)',
     0x77 => 'Old style - Subblock',
     0x78 => 'Old style - Recovery record',
     0x79 => 'Old style - Archive authenticity',
     0x7A => 'New-format subblock',
     0x7B => 'Archive end'
);

class FileType {
    const MKV = 'MKV';
    const AVI = 'AVI';
    const MP4 = 'MP4';
    const Unknown = '';
}

// cli progs are cool
if (!empty($argc) && strstr($argv[0], basename(__FILE__))) {
    /* How to use the CLI version in Windows:
        - Download and install PHP.  http://windows.php.net/download/
        - Run this script by entering something like
            C:\Program Files (x86)\PHP\php.exe rescene.php
          in the command prompt.
        - [Add 'C:\Program Files (x86)\PHP' to your systems Path environment variable
          to be able to run PHP from anywhere.]
        - To run this script from everywhere, create 'rescene.bat' in a directory that is in your PATH.
          For example: 'C:\Windows\rescene.bat'
          Include the following content:
            "C:\Program Files (x86)\PHP\php.exe" "C:\Windows\rescene.php" %*
          And place the PHP file accordingly.
          Enter 'rescene' anywhere to use it.
    */
    if (!array_key_exists(1, $argv)) {
        echo "The first parameter needs to be a .srr file.\n";
        echo "  -s 'file to store' (Save)\n";
        echo "  -d 'file to remove' (Delete)\n";
        echo "  -r 'file to rename' (Rename)\n";
        echo "  -v 'file to get' (View)\n";
        echo "  -x 'file to write' (eXtract)\n";
        echo "  -h 'special hash of the SRR file' (Hash)\n";
        echo "  -a 'show SRS info (sAmple)\n";
        echo "  -c 'compare two SRR files' (Compare)\n";
        echo "  -t 'runs a couple of small tests' (Testing)\n";
        exit(1);
    }
    $srr = $argv[1];

    // to test execution time
    $mtime = microtime(); 
    $mtime = explode(' ',$mtime); 
    $mtime = $mtime[1] + $mtime[0]; 
    $starttime = $mtime;

    if (array_key_exists(2, $argv)) {
        $switch = $argv[2];
        if (array_key_exists(3, $argv)) {
            $file = $argv[3];
            switch($switch) {
                case '-d': // delete
                    if (removeFile($srr, $file)) {
                        echo 'File successfully removed.';
                    } else {
                        echo 'File not found in SRR file.';
                    }
                    break;
                case '-s': // store
                    $path = '';
                    if (array_key_exists(4, $argv)) {
                        $path = $argv[4];
                    }
                    if (storeFileCli($srr, $file, $path)) {
                        echo 'File successfully stored.';
                    } else {
                        echo 'Error while storing file.';
                    }
                    break;
                case '-r': // remove
                    if (array_key_exists(4, $argv)) {
                        $newName = $argv[4];
			echo 'SRR file: ' . $srr . "\n";
			echo 'Old name: ' . $file . "\n";
			echo 'New name: ' . $newName . "\n";
                        if (renameFile($srr, $file, $newName)) {
                            echo 'File successfully renamed.';
                        } else {
                            echo 'Error while renaming file.';
                        }
                    } else {
                        echo 'Please enter a new name.';
                    }
                    break;
                case '-v': // view
                    print_r(getStoredFile($srr, $file));
                    break;
        case '-x': // extract
            // strip the path info
            $nopath = basename($file);
            $result = file_put_contents($nopath, getStoredFile($srr, $file));
            if ($result !== FALSE) {
            echo 'File succesfully extracted';
            } else {
            echo 'Something went wrong. Did you provide a correct file name with path?';
            }
                    break;   
                case '-c': // compare
                    print_r(compareSrr($srr, $file));
            break;
                default:
                    echo 'Unknown parameter. Use -r, -a, -v, -x or -c.';
            }
        } elseif ($switch === '-h') {
            echo 'The calculated content hash for this SRR file is: ';
            $result = processSrr($srr);
            echo calculateHash($srr, $result['rarFiles']);
        } elseif ($switch === '-a') {
        // show SRS info
        print_r(processSrsData(file_get_contents($srr)));
        } elseif ($switch === '-t') {
            echo 'fileNameCheckTest: ';
            if (fileNameCheckTest()) {
                echo "OK!\n";
            } else {
                echo "NOT OK!\n";
            }
            //compareSrr($srr, $srr);

            //$data = file_get_contents($srr);
            //print_r(processSrrData($data));
            //add file
            //storeFileCli($srr, 'dbmodel.png');

            //remove file
            //if(removeFile($srr, 'dbmodel.png')) {
            //    print_r("successfully removed");
            //}

            //process file
        if ($result = processSrr($srr)) {
            print_r($result);
            echo 'success';
        } else {
            echo 'failure';
        }


        }
    } else {
        $result = processSrr($srr);
        //print_r($result['storedFiles']);
        //print_r(($result['warnings']));
        //print_r(sortStoredFiles($result['storedFiles']));
        print_r($result);
    }

    // end part processing time
    $mtime = microtime(); 
    $mtime = explode(' ',$mtime); 
    $mtime = $mtime[1] + $mtime[0]; 
    $endtime = $mtime; 
    $totaltime = ($endtime - $starttime); 
    echo "\nFile processed in {$totaltime} seconds";
}

// API functions
/**
 * Processes a whole SRR file and returns an array with useful details.
 * @param string $file location to the file that needs to be read.
 */
function processSrr($file) {
    $fh = fopen($file, 'rb');
    // PHP uses cacheing for filesize() and we do not always want that!
    $stat = fstat($fh);
    // file handle gets closed afterwards
    return processSrrHandle($fh, $stat['size']);
}

/**
 * Processes a whole SRR file and returns an array with useful details.
 * @param bytes $srrFileData the contents of the SRR file.
 */
function processSrrData(&$srrFileData) {
    // http://www.php.net/manual/en/wrappers.php.php
    // Set the limit to 5 MiB. After this limit, a temporary file will be used.
    $memoryLimit = 5 * 1024 * 1024;
    $fp = fopen("php://temp/maxmemory:$memoryLimit", 'r+');
    fputs($fp, $srrFileData);
    rewind($fp);
    $fileAttributes = fstat($fp);
    return processSrrHandle($fp, $fileAttributes['size']);
}

/**
 * Closes the file handle.
 * Only used in the 2 functions above.
 */
function processSrrHandle($fileHandle, $srrSize) {
    // global $BLOCKNAME;
    $fh = $fileHandle;
    
    // variables to store all resulting data
    $appName = 'No SRR application name found';
    $stored_files = array();
    $rar_files = array();
    $archived_files = array();
    $recovery = NULL;
    $sfv = array();
    $sfv['comments'] = array();
    $sfv['files'] = array();
    $warnings = array();
    $compressed = FALSE; // it's an SRR file for compressed RARs
    
    // other initializations
    $read = 0; // number of bytes we have read so far
    $current_rar = NULL;
    
    while($read < $srrSize) {
        $add_size = TRUE;

        // to read basic block header
        $block = new Block($fh, $warnings);

        // echo 'Block type: ' . $BLOCKNAME[$block->blockType] . "\n";
        // echo 'Block flags: ' . dechex($block->flags) . "\n";
        // echo 'Header size: ' . $block->hsize . "\n";

        switch($block->blockType) {
            case 0x69: // SRR Header Block
                if ($appName !== 'No SRR application name found') {
                    array_push($warnings, 'Application name found in the middle of the SRR.');
                }
                $appName = $block->readSrrAppName();
                break;
            case 0x6A: // SRR Stored File Block
                $block->srrReadStoredFileHeader();

                // store stored file details
                $sf = array();
                $sf['fileName'] = $block->fileName;
                $sf['fileOffset'] = $block->storedFileStartOffset;
                $sf['fileSize'] = $block->addSize;
                $sf['blockOffset'] = $block->startOffset;

                // The same file can be stored multiple times.
                // This can make SRR files unnoticeably large.
                if (array_key_exists($block->fileName, $stored_files)) {
                    array_push($warnings, "Duplicate file detected! {$sf['fileName']}");
                }
                if (preg_match('/\\\\/', $block->fileName)) {
                    array_push($warnings, "Backslash detected! {$sf['fileName']}");
                }
                if ($block->addSize === 0) {
                    // an "empty" directory is allowed
                    if (strpos($sf['fileName'], '/') === FALSE) {
                        array_push($warnings, "Empty file detected! {$sf['fileName']}");
                    }
                } elseif (strtolower(substr($sf['fileName'], - 4)) === '.sfv') {
                    // we read the sfv file to grab the crc data of the rar files
                    $temp = processSfv(fread($fh, $block->addSize));
                    $sfv['comments'] = array_merge($sfv['comments'], $temp['comments']);
                    $sfv['files'] = array_merge($sfv['files'], $temp['files']);
                } 

                $block->skipBlock();
                // calculate CRC of the stored file
                $sdata = getStoredFileDataHandle($fileHandle, $block->storedFileStartOffset, $block->addSize);
                $sf['fileCrc'] = strtoupper(dechex(crc32($sdata)));
                // $sf['fileCrc'] = dechex(crc32(fread($fh, $block->addSize)));
                // $sf['fileCrc'] = hash('crc32b', fread($fh, $block->addSize));
                
                $stored_files[$block->fileName] = $sf;
                // end file size counting (_should_ not be necessary for Stored File Block)
                // -> 'ReScene Database Cleanup Script 1.0' SRRs were fixed with 'FireScene Cleanup'
                // (stored files weren't before the first SRR Rar file block)
            case 0x71: // SRR Rar File
                if (!is_null($current_rar)) {
                    $current_rar = NULL; // SRR block detected: start again
                }
                // end fall through from SRR Stored File block
                if ($block->blockType == 0x6A) {
                    break;
                }

                $add_size = FALSE;

                // read the name of the stored rar file
                $block->srrReadRarFileHeader();
                $recovery_data_removed = $block->flags & 0x1;

                // the hashmap key is only the lower case file name without the path
                // to make it possible to add the CRC data from the SFVs 
                $key = strtolower(basename($block->rarName));

                if (array_key_exists($key, $rar_files)) {
                    $f = $rar_files[$key]; 
                } else {
                    $f = array(); // array that stores the file details
                    $f['fileName'] = $block->rarName; // the path is still stored here
                    $f['fileSize'] = 0;
                    // when the SRR is build without SFV or the SFV is missing some lines
                    $f['fileCrc'] = 'UNKNOWN!';
                    // useful for actually comparing srr data
                    // $f['offsetStartSrr'] = $block->startOffset; // where the SRR block begins
                    $f['offsetStartRar'] = ftell($fh); // where the actual RAR headers begin
                }

                $rar_files[$key] = $f;

                // start counting file size
                $current_rar = $f;
                break;
            case 0x74: // RAR Packed File
                $block->rarReadPackedFileHeader();

                if (array_key_exists($block->fileName, $archived_files)) {
                    $f = $archived_files[$block->fileName]; 
                } else { // new file found in the archives
                    $f = array();
                    $f['fileName'] = $block->fileName;
                    $f['fileSize'] = $block->fileSize;
                    $f['fileTime'] = date("Y-m-d h:i:s", $block->fileTime);
                    $f['compressionMethod'] = $block->compressionMethod;
                }

                // check if compression was used
                if ($f['compressionMethod'] != 0x30) { // 0x30: Storing
                    $compressed = TRUE;
                }

                // CRC of the file is the CRC stored in the last archive that has the file
                // add leading zeros when the CRC isn't 8 characters
                $f['fileCrc'] = strtoupper(str_pad($block->fileCrc, 8, '0', STR_PAD_LEFT));
                $archived_files[$block->fileName] = $f;
                break;
            case 0x78: // RAR Old Recovery
                if (is_null($recovery)) {
                    // first recovery block we see
                    $recovery = array();
                    $recovery['fileName'] = 'Protect!'; 
                    $recovery['fileSize'] = 0; 
                }
                $recovery['fileSize'] += $block->addSize;
                if ($recovery_data_removed) {
                    $block->skipHeader();
                } else { // we need to skip the data that is still there
                    $block->skipBlock();
                }
                break;
            case 0x7A: // RAR New Subblock: RR, AV, CMT
                $block->rarReadPackedFileHeader();
                if ($block->fileName === 'RR') { // Recovery Record
                    if (is_null($recovery)) {
                        $recovery = array();
                        $recovery['fileName'] = 'Protect+'; 
                        $recovery['fileSize'] = 0; 
                    }
                    $recovery['fileSize'] += $block->addSize;
                    if (!$recovery_data_removed) {
                        $block->skipBlock();
                    }
                    break;
                } // other types have no data removed and will be fully skipped: fall through
            case 0x73: // RAR Volume Header
                // warnings for ASAP and IMMERSE -> crappy rars
                $ext = strtolower(substr($current_rar['fileName'], - 4));
                if (($block->flags & 0x0100) && $ext !== '.rar' && $ext !== '.001') {
                    array_push($warnings, "MHD_FIRSTVOLUME flag set for {$current_rar['fileName']}.");
                }
            case 0x72: // RAR Marker
            case 0x7B: // RAR Archive End
            case 0x75: // Old Comment
            case 0x76: // Old Authenticity
            case 0x77: // Old Subblock
            case 0x79: // Old Authenticity
                // no usefull stuff for us anymore: skip block and possible contents
                $block->skipBlock();
                break;
            default: // Unrecognized RAR/SRR block found!
                $block->skipBlock();
                if (!empty($current_rar['fileName'])) { // Psych.S06E02.HDTV.XviD-P0W4
                    // -> P0W4 cleared RAR archive end block: almost all zeros except for the header length field
                    array_push($warnings, "Unknown RAR block found in {$current_rar['fileName']}");
                } else { // e.g. a rar file that still has its contents
                    array_push($warnings, 'ERROR: Not a SRR file?');
					return FALSE;
					//trigger_error('Not a SRR file.', E_USER_ERROR);
                }
        }

        // calculate size of the rar file + end offset
        if (!is_null($current_rar)) {
            if ($add_size === TRUE) {
                $current_rar['fileSize'] += $block->fullSize;
            }
            // store end offset of the header data of the rar volume
            $current_rar['offsetEnd'] = ftell($fh);
            // keep the results updated 
            $rar_files[strtolower(basename($current_rar['fileName']))] = $current_rar;
        }

        // nuber of bytes we have processed
        $read = ftell($fh);
    }
    
    fclose($fh); // close the file

    // add sfv CRCs to all the rar files we have found
    foreach ($sfv['files'] as $key => $val) {
        // the capitalization between sfv and the actual file isn't always the same
        $lkey = strtolower($key);
        if (array_key_exists($lkey, $rar_files)) {
            $rar_files[$lkey]['fileCrc'] = strtoupper($val);
            // everything that stays can not be reconstructed (subs from .sfv files)
            unset($sfv['files'][$key]); // remove data from $sfv
        }
    }

    // return all info in a multi dimensional array
    return array(
        'srrSize' => $srrSize,
        'appName' => $appName,
        'storedFiles' => $stored_files,
        'rarFiles' => $rar_files,
        'archivedFiles' => $archived_files,
        // Recovery Records across all archives in the SRR data
        // the name is based on the first encountered recovery block
        // Protect! -> old style RAR recovery (before RAR 3.0)
        // Protect+ -> new style RAR recovery
        'recovery' => $recovery,
        'sfv' => $sfv, // comments and files that aren't covered by the SRR
        'warnings' => $warnings, // when something unusual is found
    'compressed' => $compressed,
    );
}

/**
 * Returns the bytes of a stored file. (or any other part of the SRR file)
 * It makes use of the data returned in the above function.
 * @param $srrFile  The name of the SRR file to read.
 * @param $offset   The location where the stored file starts in the SRR file.
 * @param $length   The size of the stored file to read.
 * @return The bytes of the file.
 */
function getStoredFileData($srrFile, $offset, $length) {
    // string file_get_contents ( string $filename [, bool $use_include_path = FALSE
    //                              [, resource $context [, int $offset = -1 [, int $maxlen ]]]] )
    // http://php.net/manual/en/function.file-get-contents.php
    return file_get_contents($srrFile, FALSE, NULL, $offset, $length);
}

function getStoredFileDataHandle($fileHandle, $offset, $length) {
    return stream_get_contents($fileHandle, $length, $offset);
}

/**
 * Same as the getStoredFileData() function, but based on the file name.
 * @param $srrfile  The name of the SRR file to read.
 * @param $filename The file we want the contents from, including the path.
 * @return The bytes of the file.
 */
function getStoredFile($srrfile, $filename) {
    $srr = processSrr($srrfile);
    
    foreach($srr['storedFiles'] as $key => $value) {
        if($key === $filename) {
            return getStoredFileData($srrfile, $value['fileOffset'], $value['fileSize']);
        }
    }

    // // a faster approach for the fun of it: 
    // $fh = fopen($srrfile, 'rb');
    // $sentinel = 0;
    // while ($sentinel < filesize($srrfile)) {
    //     $warnings_stub = array();
    //     $block = new Block($fh, $warnings_stub);
    //     $jump = $block->hsize + $block->addSize;
    //
    //     if ($block->blockType === 0x6A) { // a stored file block found
    //         $block->srrReadStoredFileHeader();
    //         if ($block->fileName === $filename) {
    //             return getStoredFileData($srrfile, $block->storedFileStartOffset, $block->addSize);
    //         }
    //         $block->skipBlock();
    //     } elseif ($block->blockType === 0x74 or $block->blockType === 0x78 ||
    //               ($block->blockType === 0x7A && $block->fileName === 'RR')) {
    //         // a rar packed file or recovery block that has its content removed
    //         $jump = $block->hsize;
    //         $block->skipHeader();
    //     } else {
    //         $block->skipBlock();
    //     }
    //     $sentinel += $jump;
    // }
}

/**
 * Removes a file stored in the SRR file.
 * @param   string  $srr        Path of the SRR file.
 * @param   string  $filename   Path and name of the file to remove.
 * @return TRUE on success, FALSE otherwise
 */
function removeFile($srr, $filename) {
    $result = processSrr($srr);

    foreach ($result['storedFiles'] as $key => $value) {
        if ($value['fileName'] === $filename) {
            // how much to remove? read the block starting from the offset
            $fh = fopen($srr, 'rb');
            $before = fread($fh, $value['blockOffset']);
            $warnings_stub = array();
            $block = new Block($fh, $warnings_stub);
            fseek($fh, $value['blockOffset'] + $block->fullSize, SEEK_SET);
            $after = fread($fh, $result['srrSize']); // srrSize: the (max) amount to read
            fclose($fh);
            file_put_contents($srr, $before . $after, LOCK_EX);
            return TRUE;
        }
    }
    return FALSE;
}

/**
 * Adds a file to the saved files inside a SRR file.
 * @param string    $srr    The path of the SRR file.
 * @param string    $file   The file to store.
 * @param bytes     $path   The path that must be prefixed for the file name.
 * @return TRUE on success, FALSE otherwise.
 */
function storeFileCli($srr, $file, $path='') {
    // the path must have the path separator included
    if ($path != '' && substr($path, -1) !== '/') {
        return FALSE;
    }
    $fileContents = file_get_contents($file);
    return storeFile($srr, $path . basename($file), $fileContents);
}

/**
 * Adds a file to the saved files inside a SRR file.
 * @param string    $srr        The path of the SRR file.
 * @param string    $filePath   The path and name that will be stored.   
 * @param bytes     $fdata      The bytes of the file to store in the SRR file.
 * @return TRUE when storing succeeds.
 */
function storeFile($srr, $filePath, $fdata) {
    // check for illegal windows characters
    // the path separator must be /
    // twice (//) may not be possible
    if (fileNameCheck($filePath)) {
        return FALSE;
    }
    // TODO: it is possible that you only have a path/ 

    // don't let the same file get added twice
    $result = processSrr($srr);
    foreach($result['storedFiles'] as $key => $value) {
        if($key === $filePath) {
            return FALSE;
        }
    }

    $offset = newFileOffset($srr);
    if ($offset < 0) {
        return FALSE;
    }

    $fh = fopen($srr, 'rb');
    $before = fread($fh, $offset);
    $after = fread($fh, filesize($srr));
    fclose($fh);

    $header = createStoredFileHeader($filePath, strlen($fdata));
    file_put_contents($srr, $before . $header . $fdata . $after, LOCK_EX);
    return TRUE;
}

/**
 * Renames a stored file.
 * @param string $srr     The path of the SRR file.
 * @param string $oldName The path and file name of a stored file.
 * @param string $newName The new path and file name of a stored file.
 * @return TRUE on success, FALSE otherwise.
 */
function renameFile($srr, $oldName, $newName) {
    if (fileNameCheck($newName)) {
	print_r("The new file name is illegal. Use only forward slashes for paths.\n");
        return FALSE;
    }
    $result = processSrr($srr);

    // prevent renaming to a file that already exists
    foreach ($result['storedFiles'] as $key => $value) {
	if ($key === $newName) {
	    return FALSE;
	}
    }

    foreach ($result['storedFiles'] as $key => $value) {
        if ($value['fileName'] === $oldName) {
            $fh = fopen($srr, 'rb');
            $before = fread($fh, $value['blockOffset']);
            $warnings_stub = array();
            $block = new Block($fh, $warnings_stub);
            $block->srrReadStoredFileHeader();
            fseek($fh, $value['blockOffset'] + $block->hsize, SEEK_SET);
            $after = fread($fh, $result['srrSize']); // srrSize: the (max) amount to read
            fclose($fh);

            // allow an ending on / only for empty files -> srr crashes
            if (substr($newName, - 1) === '/') { // && $block->addSize > 0) {
                return FALSE;
            }

            $changedHeader = createStoredFileHeader($newName, $block->addSize);
            file_put_contents($srr, $before . $changedHeader . $after, LOCK_EX);
            return TRUE;
        }
    }
    return FALSE;
}

/**
 * Calculate hash to identify SRRs that cover the same RAR volumes.
 * @param string $srr The SRR file.
 * @param array $rarFiles The resulting array from processSrr().
 * @return Sha1 hash of the srr file
 */
function calculateHash($srr, $rarFiles, $algorithm='sha1') {
    // do the calculation only on the sorted RAR volumes
    // this way it still yields the same result if the order of creation differs
    uasort($rarFiles, 'rarFileCmp'); // sort on filename without path, case insensitive
    // compared with pyReScene when capitals are used: same behavior
    // Parlamentet.S06E02.SWEDiSH-SQC
    $hashContext = hash_init($algorithm); 

    // calculate hash only on the RAR metadata
    foreach ($rarFiles as $key => $value) {
        $start = $value['offsetStartRar'];
        $end = $value['offsetEnd'];
        $data = getStoredFileData($srr, $start, ($end - $start));
        hash_update($hashContext, $data);
    }
    return hash_final($hashContext);
}

// Comparison function
function rarFileCmp($a, $b) {
    if ($a['fileName'] == $b['fileName']) {
        return 0;
    }
    return (strtolower($a['fileName']) < strtolower($b['fileName'])) ? -1 : 1;
}

function calculateHashHandle($srrHandle, $rarFiles, $algorithm='sha1') {
    // do the calculation only on the sorted RAR volumes
    // this way it still yields the same result if the order of creation differs
    asort($rarFiles); // Sort an array and maintain index association
    $hashContext = hash_init($algorithm); 

    // calculate hash only on the RAR metadata
    foreach ($rarFiles as $key => $value) {
        echo $key;
        $start = $value['offsetStartRar'];
        $end = $value['offsetEnd'];
        $data = getStoredFileDataHandle($srrHandle, $start, ($end - $start));
        hash_update($hashContext, $data);
    }
    return hash_final($hashContext);
}

function calculateHashString($srrData, $rarFiles, $algorithm='sha1') {
    // do the calculation only on the sorted RAR volumes
    // this way it still yields the same result if the order of creation differs
    asort($rarFiles);
    $hashContext = hash_init($algorithm); 

    // calculate hash only on the RAR metadata
    foreach ($rarFiles as $key => $value) {
        echo $key;
        $start = $value['offsetStartRar'];
    $end = $value['offsetEnd'];

        $memoryLimit = 5 * 1024 * 1024;
    $fp = fopen("php://temp/maxmemory:$memoryLimit", 'r+');
    fputs($fp, $srrData);
    rewind($fp);
    $fileAttributes = fstat($fp);

        $data = getStoredFileDataHandle($fp, $start, ($end - $start));
        hash_update($hashContext, $data);
    }
    return hash_final($hashContext);
}


/**
 * Compare 2 SRR files and list the differences.
 * @param $one First SRR file.
 * @param $two Second SRR file.
 * @return Some complicated array with differences.
 */
function compareSrr($one, $two) {
    $rone = processSrr($one);
    $rtwo = processSrr($two);
    return compareSrrRaw($rone, $rtwo, $one, $two);
}

/**
 * Same as above, but the info arrays of the SRR files were read before.
 * 2 times less parsing of the SRR files.
 */
function compareSrrRaw($rone, $rtwo, $one, $two) {
    $hashOne = calculateHash($one, $rone['rarFiles']);
    $hashTwo = calculateHash($two, $rtwo['rarFiles']);

    // ----- The RARs -----
    // rebuild data can be considered the same?
    $sameRarData = $hashOne === $hashTwo;

    // hash => file name
    $hashesOne = hashParts($one, $rone['rarFiles']);
    $hashesTwo = hashParts($two, $rtwo['rarFiles']);

    // hash => file name (of those names unique to the first array)
    $left = array_diff($hashesOne, $hashesTwo);
    $right = array_diff($hashesTwo, $hashesOne);

    if ($sameRarData && count(array_merge($left, $right)) === 0) {
        $sameRarNames = TRUE;
    } else {
        $sameRarNames = FALSE;
        // must be picked in the comparison as the other one doesn't have it
        $uniqueRarOne = array_values(array_diff_key($hashesOne, $hashesTwo));
        $uniqueRarTwo = array_values(array_diff_key($hashesTwo, $hashesOne));
        
        // of the ones that are the same, the best name should be picked by default
        $twiceHash = array_keys(array_intersect_key($left, $right));
        $namesRarOne = array();
        $namesRarTwo = array();

        foreach ($twiceHash as $value) {
            $l = $left[$value];
            $r = $right[$value];

            // heuristic: we want the one with the longest length
            // this one probably has a path added
            if (strlen($l) > strlen($r)) {
                array_push($namesRarOne, $l);
            } else {
                array_push($namesRarTwo, $r);
            }
        }
    }

    // ----- The stored files -----
    // we compare .nfo, .sfv, .srs and other files to check if they are the same
    // or not a notewhorthy difference (line endings, sfv comments, ...)
    // if they are the same, only the filename/path needs to be chosen
    $filesOne = $rone['storedFiles'];
    $filesTwo = $rtwo['storedFiles'];

    // same name, same data => OK
    // different name, same data => one of both probably has a bad name (paths should always be the same for nfos)
    $same = array(); // list of tuples (fileOne, fileTwo, best) (because they can have different names)
    $sameName = array(); // same name, different data => e.g. Mr.X and Mr.Y sitescripts banner added for NFOs
    // suggest the largest file?

    // different name, different data => nfos from fixes ect.
    $uniqueOne = $filesOne;
    $uniqueTwo = $filesTwo;


    // *** NFO ***
    $oneNfo = getFilesByExt($filesOne, '.nfo');
    $twoNfo = getFilesByExt($filesTwo, '.nfo');
    // do not process these files again
    // Returns an array containing all the values from array1 that are not present in any of the other arrays.
    $filesOne = array_diff_assoc($filesOne, $oneNfo);
    $filesTwo = array_diff_assoc($filesTwo, $twoNfo);
    $oneNfo = addNfoHash($oneNfo, $one);
    $twoNfo = addNfoHash($twoNfo, $two);

    foreach ($oneNfo as $okey => $ovalue) {
        foreach ($twoNfo as $tkey => $tvalue) {
        $toUnset = FALSE;
            if ($ovalue['hash'] === $tvalue['hash']) {
        array_push($same, array($okey, $tkey,
                'lines1' => $ovalue['lines'], 'lines2' => $tvalue['lines']));
                $toUnset = TRUE;
            } elseif ($ovalue['fileName'] === $tvalue['fileName']) {
        // suggest the largest NFO file
        if ($ovalue['fileSize'] > $tvalue['fileSize']) {
            $best = 0;
        } else {
            $best = 1;
        }
        array_push($sameName, array($okey, $tkey, 'best' => $best, 
                'lines1' => $ovalue['lines'], 'lines2' => $tvalue['lines']));
                $toUnset = TRUE;
                // TODO: show text diff?
            }
            if ($toUnset) {
        // remove from the array
                unset($uniqueOne[$okey]);
                unset($uniqueTwo[$tkey]);
            }
        }
    }

    // *** SFV ***
    $oneSfv = getFilesByExt($filesOne, '.sfv');
    $twoSfv = getFilesByExt($filesTwo, '.sfv');
    // do not process these files again
    $filesOne = array_diff_assoc($filesOne, $oneSfv);
    $filesTwo = array_diff_assoc($filesTwo, $twoSfv);
    $oneSfv = addSfvInfo($oneSfv, $one);
    $twoSfv = addSfvInfo($twoSfv, $two);

    foreach ($oneSfv as $okey => $ovalue) {
        foreach ($twoSfv as $tkey => $tvalue) {
        $toUnset = FALSE;
            if ($ovalue['files'] === $tvalue['files']) {
        // suggest the SFV file with the most comments
        if (count($ovalue['comments']) > count($tvalue['comments'])) {
            $best = 0;
        } elseif (count($ovalue['comments']) < count($tvalue['comments'])) {
            $best = 1;
        } else {
            // SFV with the longest file name has probably path info
            if (strlen($ovalue['fileName']) > strlen($tvalue['fileName'])) {
            $best = 0;
            } else {
            $best = 1;
            }
        }
                array_push($same, array($okey, $tkey, 'best' => $best));
                $toUnset = TRUE;
            } elseif ($ovalue['fileName'] === $tvalue['fileName']) {
                array_push($sameName, array($okey, $tkey));
                $toUnset = TRUE;
            }
            if ($toUnset) {
                unset($uniqueOne[$okey]);
                unset($uniqueTwo[$tkey]);
            }
        }
    }

    // *** SRS ***
    $oneSrs = getFilesByExt($filesOne, '.srs');
    $twoSrs = getFilesByExt($filesTwo, '.srs');
    // do not process these files again
    $filesOne = array_diff_assoc($filesOne, $oneSrs);
    $filesTwo = array_diff_assoc($filesTwo, $twoSrs);
    $oneSrs = addSrsInfo($oneSrs, $one);
    $twoSrs = addSrsInfo($twoSrs, $two);

    //print_r($oneSrs); 
    //print_r($twoSrs); 
    foreach ($oneSrs as $okey => $ovalue) {
        foreach ($twoSrs as $tkey => $tvalue) {
        $toUnset = FALSE;
        // sample name and crc32 must be the same to be the same sample
        if ($ovalue['fileData']->name === $tvalue['fileData']->name &&
        $ovalue['fileData']->crc32 === $tvalue['fileData']->crc32) {
        // checked agains main AVI/MKV file
        if ($ovalue['trackData'][1]->matchOffset === $tvalue['trackData'][1]->matchOffset) {
            // equal enough
            array_push($same, array($okey, $tkey));
            $toUnset = TRUE;
        } else {
            // -c parameter difference
            // indicate which one had the -c parameter used
            if ($ovalue['trackData'][1]->matchOffset != 0) {
            $best = 0;
            } elseif ($tvalue['trackData'][1]->matchOffset != 0) {
            $best = 1;
            } else {
            // suggest longest file name
            if (strlen($ovalue['fileName']) > strlen($tvalue['fileName'])) {
                $best = 0;
            } else {
                $best = 1;
            }            }
            array_push($sameName, array($okey, $tkey, 'best' => $best));
            $toUnset = TRUE;
        }
            }
            if ($toUnset) {
                unset($uniqueOne[$okey]);
                unset($uniqueTwo[$tkey]);
            }
        }
    }
    
    // *** OTHER ***
    foreach ($filesOne as $okey => $ovalue) {
        foreach ($filesTwo as $tkey => $tvalue) {
        $toUnset = FALSE;
        // same CRC: exactly the same
            if ($ovalue['fileCrc'] === $tvalue['fileCrc']) {
                array_push($same, array($okey, $tkey));
                $toUnset = TRUE;
        // they only have the same name
            } elseif ($ovalue['fileName'] === $tvalue['fileName']) {
                array_push($sameName, array($okey, $tkey));
                $toUnset = TRUE;
            }
            if ($toUnset) {
                unset($uniqueOne[$okey]);
                unset($uniqueTwo[$tkey]);
            }
        }
    }

    $result = array(
        'sameRarData' => $sameRarData,
        'sameRarNames' => $sameRarNames,
        
        'same' => $same,
        'sameName' => $sameName,
        'uniqueOne' => array_keys($uniqueOne),
        'uniqueTwo' => array_keys($uniqueTwo),
    );
    if (!$sameRarNames) {
        // these 4 lists cover all unique RAR metadata
        $result = array_merge($result, array(
            'uniqueRarOne' => $uniqueRarOne, // RAR files that are new
            'uniqueRarTwo' => $uniqueRarTwo,
            'namesRarOne' => $namesRarOne, // the RAR names that are better (when content is the same)
            'namesRarTwo' => $namesRarTwo, // these should be picked by default when mergeing
        ));
    }
    return $result;
}

function getFilesByExt($fileList, $extention) {
    $result = array();

    foreach ($fileList as $key => $value) {
        if (strtolower(substr($value['fileName'], - 4)) === $extention) {
            $result[$key] = $value;
        }
    }
    return $result;
}

function addNfoHash($list, $srrFile) {
    foreach($list as $key => $value) {
        // store nfo hash next to the other stored file data
    $nfoData = getStoredFileData($srrFile, $value['fileOffset'], $value['fileSize']);
        $list[$key]['hash'] = nfoHash($nfoData);
    // check for which nfo has the fewest lines -> probably no unnessesary white lines
    // Indiana.Jones.And.The.Last.Crusade.1989.PAL.DVDR-DNA
    $list[$key]['lines'] = count(explode("\n", $nfoData));
    }
    return $list;
}

function addSfvInfo($list, $srrFile) {
    foreach($list as $key => $value) {
        $result = processSfv(getStoredFileData($srrFile, $value['fileOffset'], $value['fileSize']));
        $list[$key]['comments'] = $result['comments'];
        $list[$key]['files'] = $result['files'];
    }
    return $list;
}

function addSrsInfo($list, $srrFile) {
    foreach($list as $key => $value) {
        $result = processSrsData(getStoredFileData($srrFile, $value['fileOffset'], $value['fileSize']));
    //print_r($result);
        $list[$key] += $result;
    }
    return $list;
} 

function nfoHash($nfoData) {
    // ignore all new lines
    $string = preg_replace("/(\r\n|\r|\n)/", '', $nfoData);
    // trailing whitespace can be stripped sometimes too
    $string = rtrim($string);
    return md5($string);
}

/**
 * Merge two SRR files by selecting the wanted data parts from each of them.
 * @param $one First SRR file.
 * @param $two Second SRR file.

 */
function mergeSrr($one, $two, $storeOne, $storeTwo, $rarOne, $rarTwo, $result) {
    $rone = processSrr($one);
    $rtwo = processSrr($two);




}

function processSrsData($srsFileData) {
    // http://www.php.net/manual/en/wrappers.php.php
    // Set the limit to 5 MiB. After this limit, a temporary file will be used.
    $memoryLimit = 5 * 1024 * 1024;
    $fp = fopen("php://temp/maxmemory:$memoryLimit", 'r+');
    fputs($fp, $srsFileData);
    rewind($fp);
    $fileAttributes = fstat($fp);
    return processSrsHandle($fp, $fileAttributes['size']);
}

function processSrsHandle($fileHandle, $srsSize) {
    switch(detectFileFormat($fileHandle)) {
        case FileType::AVI:
            $result = parse_srs_avi($fileHandle, $srsSize);
            break;
        case FileType::MKV:
            $result = parse_srs_mkv($fileHandle, $srsSize);
            break;
        case FileType::MP4:
            $result = parse_srs_mp4($fileHandle, $srsSize);
            break;
        default:
            echo 'SRS file type not detected';
    }
    fclose($fileHandle);
    return $result;
}

/**
 * Returns the list of stored files in a sorted way.
 * Directories first, then the files.
 * @param $storedFiles result from processSrr().
 */
function sortStoredFiles($storedFiles) {
    //TODO: nfo at the top
    // the keys are the unique file name
    $all = array_keys($storedFiles);

    // folders on top, files under that
    $dirs = array_filter($all, "isFolder");
    $files = array_diff($all, $dirs);

    sort($dirs);
    sort($files);

    return array_merge($dirs, $files);
}

// Private helper functions -------------------------------------------------------------------------------------------

function isFolder($dir) {
    return (strpos($dir, '/', 1) !== FALSE);
}

/**
 * No illegal Windows characters.
 * No \ as path separator.
 * No // (double forward slashes).
 * The string cannot start with a /.
 * The string must contain at least one character.
 */
function fileNameCheck($path) {
    return preg_match('/([\\\\:*?"<>|]|\/\/)|^\/|^$/', $path);
}

function fileNameCheckTest() {
    return (!fileNameCheck('ok.ext') &&
            fileNameCheck('dir\file.ext') &&
            fileNameCheck('dir/file:file.ext') &&
            fileNameCheck('dir/file*.ext') &&
            fileNameCheck('dir/file?.ext') &&
            fileNameCheck('dir/file".ext') &&
            fileNameCheck('dir/file<.ext') &&
            fileNameCheck('dir/file>.ext') &&
            fileNameCheck('dir/file|.ext') &&
            fileNameCheck('dir//file.ext') &&
            fileNameCheck('dir\\\\file.ext') &&
            fileNameCheck('/dir/file.ext') &&
            fileNameCheck('') &&
            fileNameCheck('dir\\file.ext'));
}

/**
 * Hash all RAR metadata parts of a SRR file.
 * @param $srr The SRR file.
 * @param $rarFiles Subarray result from processSrr().
 */
function hashParts($srr, $rarFiles) {
    $hashes = array();
    foreach($rarFiles as $key => $value) {
        $start = $value['offsetStartRar'];
        $end = $value['offsetEnd'];
        $hash = sha1(getStoredFileData($srr, $start, ($end - $start)));
        $hashes[$hash] = $value['fileName'];
    }
    return $hashes;
}

/**
 * Construct the header of a SRR stored file block. 
 * @param string $name The path (including file name) of the file to store.
 * @param int $fileSize The size of the stored file.
 */
function createStoredFileHeader($name, $fileSize) {
    // 2 byte CRC, 1 byte block type, 2 bytes for the flag 0x8000: addsize field is present
    $header = pack('H*' , '6A6A6A0080');
    $addSize = pack('V', $fileSize);
    $pathLength = pack('v', strlen($name));
    $headerSize = pack('v', 5 + 2 + 4 + 2 + strlen($name));
    return $header . $headerSize . $addSize . $pathLength . $name;
}

/**
 * We choose the offset to insert a new file to be after the SRR Header Block
 * to keep all files always at the front and to anticipate new SRR blocks.
 * @param string $srr   The path of the SRR file to open.
 */
function newFileOffset($srr) {
    $fh = fopen($srr, 'rb');
    // read SRR Header Block
    $warnings_stub = array();
    $block = new Block($fh, $warnings_stub);
    if ($block->blockType === 0x69) {
        $block->readSrrAppName();
    } else {
        return -1;
    }

    $offset = ftell($fh);
    fclose($fh);
    return $offset;
}

/**
 * Returns an array with comments and files
 * @param $data Binary datastream of SFV file.
 */
function processSfv($data) {
    // create array of all the lines
    $lines = explode("\n", $data);
    $result = array();
    $result['comments'] = array();
    $result['files'] = array();

    for ($i=0; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        $lineLength = strlen($line);

        // process if line has contents
        if ($lineLength > 0) {
            // the line is a comment line or it is too short
            if (in_array($line[0], array(';')) or $lineLength < 10) {
                array_push($result['comments'], $line);
            } else {
                // parse SFV line
                $spaceIndex = strrpos($line, ' ');
                $fileName = substr($line, 0, $spaceIndex);
                // TODO: A sfv file can contain multiple spaces in between
                // TODO: the crc isn't always 8 chars
                $result['files'][$fileName] = substr($line, $spaceIndex + 1, 8);
            }
        }
    }    

    // print_r($result);
    return $result;
}

/**
 * Converts MS DOS timestamp to something usable.
 * DosDateTimeToFileTime()
 * http://msdn.microsoft.com/en-us/library/ms724247(v=vs.85).aspx
 * @returns date in unix time
 */
function parseDosTime($dword) {
    $second = ($dword & 0x1F) * 2;
    $dword = $dword >> 5;
    $minute = $dword & 0x3F;
    $dword = $dword >> 6;
    $hour = $dword & 0x1F;
    $dword = $dword >> 5;
    $day = $dword & 0x1F;
    $dword = $dword >> 5;
    $month = $dword & 0x0F;
    $dword = $dword >> 4;
    $year = ($dword & 0x7F) + 1980;
    return mktime($hour, $minute, $second, $month, $day, $year);
}


/**
 * A RAR or SRR block used for reading all header fields.
 */
class Block {
    /**
     * The constructor initializes a RAR block by reading the the basic 7 byte
     * header fields. It checks if there is content after the block header.
     * @param   open file handle
     */
    public function __construct($fileHandle, &$warnings) {
        $this->fh = $fileHandle;
        $this->startOffset = ftell($fileHandle); // current location in the file
        $this->warnings = &$warnings;

        // reading basic 7 byte header block
        $array = unpack('vheaderCrc/CblockType/vflags/vheaderSize', fread($this->fh, 7));
        $this->headerCrc = $array['headerCrc'];
        $this->blockType = $array['blockType'];
        $this->flags = $array['flags'];
        $this->hsize = $array['headerSize']; 
        $this->addSize = 0; // size of data after the header

        // check if block contains additional data
        $addSizeFlag = $array['flags'] & 0x8000;
        if ($addSizeFlag || ($array['blockType'] === 0x74) || ($array['blockType'] === 0x7A)) {
            // The BiA guys need some bitch slapping first:
            // they don't set the 0x8000 flag for RAR file blocks.
            if (!$addSizeFlag) {
                array_push($warnings, 'LONG_BLOCK flag (0x8000) not set for RAR File block (0x74).');
            }
            $array = unpack('VaddSize', fread($this->fh, 4));
            $this->addSize = $array['addSize'];
        }

        // only used to calculate the full size of a rar file
        // size header + size stored content
        // this content is removed for RAR blocks
        $this->fullSize = $this->hsize + $this->addSize;

        // -- check CRC of block header --
        $offset = ftell($this->fh);
        fseek($this->fh, $this->startOffset + 2, SEEK_SET);
        $crcData = fread($this->fh, $this->hsize - 2);
        // only the 4 lower order bytes are used
        $crc = crc32($crcData) & 0xffff;
        // igonore blocks with no CRC set (same as twice the blockType)
        if ($crc !== $this->headerCrc && $this->headerCrc !== 0x6969 // SRR Header
                                      && $this->headerCrc !== 0x6a6a // SRR Stored File
                                      && $this->headerCrc !== 0x7171 // SRR RAR block
                                      && $this->blockType !== 0x72 // RAR marker block (fixed: magic number)
        ) {
        // it can not fail here for releases such as Haven.S02E05.HDTV.XviD-P0W4
            global $BLOCKNAME;
        array_push($warnings, 'ERROR: Invalid block header CRC found: header is corrupt. (' . 
            $BLOCKNAME[$this->blockType] . ', ' . $offset . ')');
        }
        // set offset back to where we started from
        fseek($this->fh, $offset, SEEK_SET);
    }

    /**
     * Reads the additional fields for a SRR Header Block.
     * @return  string  Name of the application used to create the SRR file.
     */
    function readSrrAppName() {
        if ((dechex($this->flags) & 1)) {
            // read 2 fields after basic header block
            $length = unpack('vnamelength', fread($this->fh, 2));
            return fread($this->fh, $length['namelength']);
        } else {
            return ""; // there isn't an application name stored
        }
    }
    
    /**
     * Reads the additional fields for a SRR Stored File Block.
     */
    function srrReadStoredFileHeader() {
        $array = unpack('vlength', fread($this->fh, 2));
        if ($array['length'] !== 0) {
            $this->fileName = fread($this->fh, $array['length']);
        } else {
            array_push($this->warnings, 'Stored file with no name detected!');
            $this->fileName = "";
        }
        $this->storedFileStartOffset = ftell($this->fh);

        // skip possible (future) fields to start file
        $this->skipHeader();
    }

    /**
     * Reads the additional fields for a SRR block that indicates
     * that RAR blocks are following.
     */
    function srrReadRarFileHeader() {
        $length = unpack('vlength', fread($this->fh, 2));
        if ($length['length'] !== 0) {
            $this->rarName = fread($this->fh, $length['length']);
        } else {
            array_push($this->warnings, 'RAR file with no name detected!');
            $this->rarName = "";
        }
    }

    /**
     * Reads the additional fields for a RAR file block.
     */
    function rarReadPackedFileHeader() {
        $array = unpack('Vus/Cos/VfileCrc/VfileTime/CunpackVersion/Cmethod/vnameSize/Vattr',
                        fread($this->fh, 21));
        // $this->packSize = $this->addSize
        // $this->unpackedSize = $array['us'];
        $this->fileSize = $array['us'];
        $this->fileCrc = dechex($array['fileCrc']);
        // $this->os = $array['os'];
        $this->fileTime = parseDosTime($array['fileTime']);
        // $this->unpackVersion = $array['unpackVersion'];
        $this->compressionMethod = $array['method'];
        // $this->fileAttributes = $array['attr'];

        if ($this->flags & 0x100) {
            $high = unpack('VhighPackSize/VhighUnpackSize', fread($this->fh, 8));
            // $this->highPackSize = $high['highPackSize'];
            // $this->highUnpackSize = $high['highUnpackSize'];
            // add the high order bits before the low order bits and convert to decimal
            $lowhex = str_pad(dechex($array['us']), 8, '0', STR_PAD_LEFT);
            $highhex = dechex($high['highUnpackSize']);
            $this->fileSize = hexdec($highhex . $lowhex);
        }
        
        // only grab the ascii representation of the name
        $fname = explode('\0', fread($this->fh, $array['nameSize']), 1);
        $this->fileName = $fname[0];

        // salt and extra time fields are here and not interesting
        $this->skipHeader();
    }
    
    /**
     * Set the file handle cursor at the end of the header.
     * Data that follows can be a next block or a stored file.
     */
    function skipHeader() {
        // skip whole header of the block
        fseek($this->fh, $this->startOffset + $this->hsize, SEEK_SET);
    }
    
    /**
     * Sets file cursor to the next block based on the values in the header!
     */
    function skipBlock() {
        fseek($this->fh, $this->startOffset + $this->hsize + $this->addSize, SEEK_SET);
    }
}

function detectFileFormat($fileHandle) {
    $ft = FileType::Unknown;
    $firstBytes = strtoupper(bin2hex(fread($fileHandle, 4)));

    switch($firstBytes) {
        case '1A45DFA3': 
        $ft = FileType::MKV;
        break;
    case '52494646': // RIFF
        $ft = FileType::AVI;
        break;
    default:
		if ('66747970' ===  bin2hex(fread($fileHandle, 4))) {
			$ft = FileType::MP4;
        }
    }
    rewind($fileHandle);
    return $ft;
}

function parse_srs_avi($fh, $srsSize) {
    $result = array();
    $result['trackData'] = array();

    $rr = new RiffReader($fh, $srsSize);
    $done = false;
    while (!$done && $rr->read()) {
		if ($rr->chunkType == 'LIST') {
			$rr->moveToChild();
		} else {
			if ($rr->fourcc == 'SRSF') {
			$data = $rr->readContents();
			$result['fileData'] = new FileData($data);
			} elseif ($rr->fourcc == 'SRST') {
			$data = $rr->readContents();
			$track = new TrackData($data);
			$result['trackData'][$track->trackNumber] = $track;
			} elseif ($rr->chunkType == 'MOVI') {
			$done = true;
			break;
			} else {
			$rr->skipContents();
			}
		}
    }
    return $result;
}

class EbmlType {
    const Segment = 'segment';
    const ReSample = 'resample';
    const ReSampleFile = 'resamplefile';
    const ReSampleTrack = 'resampletrack';
    const Cluster = 'cluster';
    const AttachmentList = 'attachmentlist';
    const Block = 'block';
    const Unknown = 'whatever';
}

function parse_srs_mkv($fh, $srsSize) {
    $result = array();
    $result['trackData'] = array();

    $er = new EbmlReader($fh, $srsSize);
    $done = false;
    while(!$done && $er->read()) {
		if ($er->etype == EbmlType::Segment || $er->etype == EbmlType::ReSample) {
			$er->moveToChild();
		} elseif ($er->etype == EbmlType::ReSampleFile) {
			$data = $er->readContents();
			$result['fileData'] = new FileData($data);
		} elseif ($er->etype == EbmlType::ReSampleTrack) {
			$data = $er->readContents();
			$track = new TrackData($data);
			$result['trackData'][$track->trackNumber] = $track;
		} elseif ($er->etype == EbmlType::Cluster || $er->etype == EbmlType::AttachmentList) {
			$er->skipContents();
			$done = true;
		} else {
			$er->skipContents();
		}
    }
    return $result;
}

function parse_srs_mp4($fh, $srsSize) {
    $result = array();
}

class FileData {
    public function __construct($data) {
		$u = unpack('vflags/vappLength', substr($data, 0, 4));
		$this->flags = $u['flags'];
		$this->appName = substr($data, 4, $u['appLength']);
		$v = unpack('vnameLength', substr($data, 4 + $u['appLength'], 2));
		$this->name = substr($data, 4 + $u['appLength'] + 2, $v['nameLength']);
		$offset = 4 + $u['appLength'] + 2 + $v['nameLength'];

		$w = unpack('Vlow/Vhigh/Vcrc32', substr($data, $offset, 12));
			// add the high order bits before the low order bits and convert to decimal
		$lowhex = str_pad(dechex($w['low']), 8, '0', STR_PAD_LEFT);
		$highhex = dechex($w['high']);
		$this->fileSize = hexdec($highhex . $lowhex);
		$this->crc32 = dechex($w['crc32']);
    }
}

class TrackData {
    public function __construct($data) {
		$u = unpack('vflags/vtrackNumber', substr($data, 0, 4));
		$this->flags = $u['flags'];
		$this->trackNumber = $u['trackNumber'];
		//TODO: mp4 support
		
		
		
		
		
		if ($this->flags & 0x4) { // big file
			$w = unpack('Vlow/Vhigh', substr($data, 4, 8));
			$lowhex = str_pad(dechex($w['low']), 8, '0', STR_PAD_LEFT);
			$highhex = dechex($w['high']);
			$this->dataSize = hexdec($highhex . $lowhex);
			$add = 8;
		} else {
			$w = unpack('Vsize', substr($data, 4, 4));
			$this->dataSize = $w['size'];
			$add = 4;
		}
		$w = unpack('Vlow/Vhigh', substr($data, 4 + $add, 8));
		$lowhex = str_pad(dechex($w['low']), 8, '0', STR_PAD_LEFT);
		$highhex = dechex($w['high']);
		// location where the track is located in the main file (often zero)
		$this->matchOffset = hexdec($highhex . $lowhex);
		// signature length and signature bytes we don't need
    }
}

class RiffReader {
    public function __construct($fileHandle, $srsSize) {
		$this->fh = $fileHandle;
		$this->fileSize = $srsSize;
		$this->readDone = true;

		$this->chunkType = null;
		$this->hasPadding = false;
		$this->chunkLength = 0;
		$this->fourcc = '';
    }

    public function read() {
		$chunkStartPosition = ftell($this->fh);
		$this->readDone = false;

		if ($chunkStartPosition + 8 > $this->fileSize) {
			return false;
		}

		$header = fread($this->fh, 8);
		$this->fourcc = substr($header, 0, 4);
		$this->chunkLength = unpack('Vlength', substr($header, 4, 4));
		$this->chunkLength = $this->chunkLength['length']; 

		if ($this->fourcc == 'RIFF' || $this->fourcc == 'LIST') {
			fseek($this->fh, 4, SEEK_CUR);
			//echo $this->chunkLength . "\n";
			$this->chunkLength -= 4;
			$this->chunkType = 'LIST';
		} else {
			if (ctype_digit(substr($header, 0, 2))) {
			$this->chunkType = 'MOVI';
			} else {
			$this->chunkType = '    ';
			}
		}
		$this->hasPadding = $this->chunkLength % 2 == 1;

		return true;
    }

    public function readContents() {
		if ($this->readDone) {
			fseek($this->fh, -$this->chunkLength - $this->hasPadding, SEEK_CUR);
		}

		$this->readDone = true;
		$buffer = null;

		if ($this->chunkType != 'MOVI') {
			$buffer = fread($this->fh, $this->chunkLength);
		}

		if ($this->hasPadding) {
			fseek($this->fh, 1, SEEK_CUR);
		}
		return $buffer;
    }

    public function skipContents() {
		if (!$this->readDone) {
			$this->readDone = true;

			if ($this->chunkType != 'MOVI') {
				fseek($this->fh, $this->chunkLength, SEEK_CUR);
			}

			if ($this->hasPadding) {
				fseek($this->fh, 1, SEEK_CUR);
			}
		}
    }

    public function moveToChild() {
		$this->readDone = true;
    }
}

class EbmlReader {
    public function __construct($fileHandle, $srsSize) {
		$this->fh = $fileHandle;
		$this->fileSize = $srsSize;
		$this->readDone = true;

		$this->etype = null;
		$this->elementLength = 0;
    }

    private function String2Hex($string){
		$hex='';
			for ($i=0; $i < strlen($string); $i++){
				$hex .= str_pad(dechex(ord($string[$i])), 2,  '0', STR_PAD_LEFT);
			}
		return $hex;
    }
    
    public function read() {
		assert ($this->readDone == true || $this->etype == EbmlType::Block);
		// too little data
		if (ftell($this->fh) + 2 > $this->fileSize) {
			return false;
		}

		$this->readDone = false;

		// element ID
		$readByte = ord(fread($this->fh, 1));
		$idLengthDescriptor = $this->getUIntLength($readByte);
		$elementHeader = str_pad(dechex($readByte), 2,  '0', STR_PAD_LEFT);
		if ($idLengthDescriptor > 1) {
			$elementHeader .= $this->String2Hex(fread($this->fh, $idLengthDescriptor - 1));
		}

		// data size
		$readByte = ord(fread($this->fh, 1));
		$dataLengthDescriptor = $this->getUIntLength($readByte);
		$elementHeader .= str_pad(dechex($readByte), 2,  '0', STR_PAD_LEFT);
		if ($dataLengthDescriptor > 1) {
			$elementHeader .= $this->String2Hex(fread($this->fh, $dataLengthDescriptor - 1));
		}

		assert ($idLengthDescriptor + $dataLengthDescriptor == strlen($elementHeader)/2);
		if ($idLengthDescriptor + $dataLengthDescriptor != strlen($elementHeader)/2)
			exit();

		// data
		$eh = strtoupper(substr($elementHeader, 0, 2*$idLengthDescriptor));
		switch ($eh) {
			case 'A1':
			case 'A2':
				$this->etype = EbmlType::Block;
				break;
			case '1F43B675':
				$this->etype = EbmlType::Cluster;
				break;
			case '18538067':
				$this->etype = EbmlType::Segment;
				break;
			case '1941A469':
				$this->etype = EbmlType::AttachmentList;
				break;
			case '1F697576':
				$this->etype = EbmlType::ReSample;
				break;
			case '6A75':
				$this->etype = EbmlType::ReSampleFile;
				break;
			case '6B75':
				$this->etype = EbmlType::ReSampleTrack;
				break;
			default:
				$this->etype = EbmlType::Unknown;
		}

		$this->elementLength = $this->getEbmlUInt($elementHeader, $idLengthDescriptor, $dataLengthDescriptor);

		return true;
    }

    private function getUIntLength($lengthDescriptor) {
		$length = 0;
		for ($i=0;$i<8;$i++) {
			if (($lengthDescriptor & (0x80 >> $i)) != 0) {
				$length = $i + 1;
				break;
			}
		}
		return $length;
    }

    private function getEbmlUInt($buff, $offset, $count) {
		$size = hexdec(substr($buff, $offset*2, 2)) & (0xFF >> $count);
		for ($i=1;$i<$count;$i++) {
			$size = ($size << 8) + hexdec(substr($buff, $offset*2+$i*2, 2));
		}
		return $size;
    }

    public function readContents() {
		if ($this->readDone) {
			fseek($this->fh, -$this->elementLength, SEEK_CUR);
		}

		$this->readDone = true;
		$buffer = null;

		// skip over removed ebml elements
		if ($this->etype != EbmlType::Block) {
			$buffer = fread($this->fh, $this->elementLength);
		}
		return $buffer;
    }

    public function skipContents() {
		if (!$this->readDone) {
			$this->readDone = true;

			if ($this->etype != EbmlType::Block) {
			fseek($this->fh, $this->elementLength, SEEK_CUR);
			}
		}
    }

    public function moveToChild() {
		$this->readDone = true;
    }
}

/* ----- end of rescene.php ----- */
