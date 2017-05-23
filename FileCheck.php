<?php

/**
* This class provides basic checks for an uploaded file. It exposes
* common attributes for the uploaded file (e.g. name, mime, location)
* and needs to pass validation for the upload to succeed.
*/
namespace CLC\Upload;

class FileCheck {

    /**
    * @var string       $uploadAttachment       $_Files object
    * @var bool         $isImage                togged on detection of jpg
    * @var string       $mime                   detected file mime Type
    * @var string       $ext                    generated extention from mime
    * @var string       $newFileName            generated random name with extention
    * @var const        $destination            upload location const from UPLOAD_DESTINATION config should be outside document root
    * @var string       $finalDestination       once uploaded final destination
    * @var bool         $errors                 bool are there errors
    * @var string       $errorMessage          string thrown with error message
    * @var array        $allowedTypes           allowed upload mimes
    * @var array        $allowedExt             allowed extention types
    * @var array        $errorTypes             array of error message types
    */
    private     $uploadAttachment;
    private     $isImage = false;
    private     $mime;
    private     $ext;
    private     $newFileName;
    private     $destination = UPLOAD_LOC;
    private     $finalDestination;
    private     $errors = false;
    private     $errorMessage;
    private     $allowedTypes = ['image/jpeg',
                                 'application/pdf',
                                 'application/msword',
                                 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    private     $allowedExt = ['image/jpeg' => 'jpg',
                               'application/pdf' => 'pdf',
                               'application/msword' => 'doc',
                               'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'];
    private     $errorTypes = [ 1 => 'The uploaded file exceeds the upload max file size',
                                   2 => 'The uploaded file exceeds the max file size in form',
                                   3 => 'The uploaded file was only partially uploaded',
                                   4 => 'No file was uploaded',
                                   5 => 'Missing a temporary folder',
                                   6 => 'Failed to write file to disk',
                                   7 => 'File upload stopped by extension'];

    /**
    * Constructor
    * @param  string       $uploadFile              The $_FILES[] key
    */
    public function __construct($uploadFile)
    {
        $this->uploadAttachment = $uploadFile;
    }

    /**
    * checkFile
    * @return                                   Self
    * @throws  Exception                        If checks on file fail for any reason
    */
    public function checkFile() {
        try {
            $this->checkMalformedError()
                 ->checkUploadError()
                 ->checkIsFile()
                 ->checkFileSize()
                 ->checkMime()
                 ->randomName()
                 ->checkExtention()
                 ->checkIsImage()
                 ->resizeImage()
                 ->moveUpload();
        }
        catch (\Exception $e) {
            $this->errors = true;
            $this->errorMessage = $e->getMessage();
            $this->setDefaults();
        }
        return $this;
    }

    /**
    * checkMalformedError callable, checks $_Files['error'] is not missing or malformed
    * @return                       Self
    * @throws Exception             If $Files['errors'] argument is malformed or missing
    */
    private function checkMalformedError() {
        if (!isset($this->uploadAttachment['error']) || is_array($this->uploadAttachment['error'])) {
            throw new \Exception('$_FILES Corruption Attack');
        }
        return $this;
    }

    /**
    * checkUploadError callable, checks $Files[] key has no errors
    * @return                       Self
    * @throws Exception             If $Files['errors'] argument is populated with error message
    */
    private function checkUploadError() {
        if ($this->uploadAttachment['error'] != 0) {
            $errorTypes = $this->errorTypes;
            throw new \Exception('Upload Error! ' . $errorTypes[$this->uploadAttachment['error']]);
          }
        return $this;
    }

    /**
    * checkIsFile callable, checks $Files[] key contains a file
    * @return                       Self
    * @throws Exception             If $Files[] argument is not a file
    */
    private function checkIsFile() {
        if (!is_uploaded_file($this->uploadAttachment['tmp_name'])) {
            throw new \Exception('No File Uploaded');
        }
        return $this;
    }

    /**
    * checkFileSize callable, checks $Files[size] isnt greater than specified size (10485760 bytes 10meg)
    * @return                       Self
    * @throws Exception             If $Files['size'] argument is > 10meg
    */
    private function checkFileSize() {
        if ($this->uploadAttachment['size'] > 10485760) {
            throw new \Exception('Exceeded filesize limit');
        }
        return $this;
    }

    /**
    * checkMime callable, gets mime type from uploaded file and updates $this->mime with string
    * @return                       Self
    */
    private function checkMime() {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $fileContents = file_get_contents($this->uploadAttachment['tmp_name']);
        $this->mime = $finfo->buffer($fileContents);
        $this->makeExtension($finfo->buffer($fileContents));
        return $this;
    }

    /**
    * makeExtention callable, takes $mimeType string and generates file extention from whitelist
    * @param string                 $mimeType           mimetype string for comparison to whitelist
    * @return                       Self
    * @throws Exception             If mimetype not in whitelist
    */
    private function makeExtension($mimeType) {
        $extensions = $this->allowedExt;
        if (!isset($extensions[$mimeType]))
        {
            throw new \Exception('Not Allowed Filetype (mime) Type');
        }
        $this->ext = $extensions[$mimeType];
        return $this;
    }

    /**
    * randomName callable, creates unique random name for new file including generated extention.
    * @return                       Self
    */
    private function randomName() {
        $this->newFileName = uniqid() . "." . $this->ext;
        return $this;
    }

    /**
    * checkExtention callable, compares final generated name againts whitelist.
    * @return                       Self
    * @throws Exception             If extention not in whitelist
    */
    private function checkExtention() {
        $validFileExtensions = array(".jpg", ".pdf", ".doc", ".docx");
        $fileExtension = strrchr($this->newFileName, ".");
        if (!in_array($fileExtension, $validFileExtensions)) {
            throw new \Exception('Not Valid Extension');
        }
        return $this;
    }

    /**
    * isImage callable, if jpg checks image size to check its not script renamed to .jpg
    * @return                       Self
    * @throws Exception             If file doesnt exist
    * @throws Exception             If upload is not a image
    */
    private function checkIsImage() {
        if ($this->ext === 'jpg') {
            $this->isImage = true;
            $shouldBeImage = $this->uploadAttachment['tmp_name'];
            $imageSizeData = getimagesize($shouldBeImage);
            if ($imageSizeData === FALSE)
            {
                throw new \Exception('Not Image file');
            }
        }
        return $this;
    }

    /**
    * resizeImage callable, if detected image resizes image to remove scripts in meta and save space on server
    * @return                       Self
    */
    private function resizeImage() {
        if ($this->isImage === true) {
            $resizeTmp = @imagecreatefromjpeg($this->uploadAttachment['tmp_name']);
            if (!$resizeTmp) {
                throw new \Exception('Malformed Image file');
            }
            $resizeScl = imagescale($resizeTmp, 1024);
            imagejpeg($resizeScl, $this->uploadAttachment['tmp_name']);
            imagedestroy($resizeTmp);
            imagedestroy($resizeScl);

        }
        return $this;
    }

    /**
    * moveUpload, moves file from php temp to final destination and updates finalDestination string
    * @return                       Self
    */
    private function moveUpload() {
        $this->finalDestination = $this->destination . $this->newFileName;
        move_uploaded_file($this->uploadAttachment['tmp_name'], $this->finalDestination);
        return $this;
    }

    /**
    * set vaules back to default can be trigged if errors detected
    * @return                       Self
    */
    private function setDefaults() {
        $this->newFileName = $this->mime = $this->ext = $this->finalDestination = null;
        $this->isImage = false;
        return $this;
    }

    /**
    * @return file upload location
    */
    public function getLocation() {
        return $this->finalDestination;
    }

    /**
    * @return file upload name
    */
    public function getFileName() {
        return $this->newFileName;
    }

    /**
    * @return file mime type
    */
    public function getFileMime() {
        return $this->mime;
    }

    /**
    * @return bool is file image
    */
    public function getIsFileImage() {
        return $this->isImage;
    }

    /**
    * @return bool any errors
    */
    public function getErrors() {
        return $this->errors;
    }

    /**
    * @return string error messages
    */
    public function getErrorMessage() {
        return $this->errorMessage;
    }

}
