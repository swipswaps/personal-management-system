<?php


namespace App\Controller\Files;

use App\Controller\Utils\Application;
use App\Controller\Utils\Env;
use App\Form\UploadFormType;
use App\Services\DirectoriesHandler;
use App\Services\FilesHandler;
use App\Services\FileUploader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class FileUploadController extends AbstractController {

    const UPLOAD_PAGE_TWIG_TEMPLATE     = 'core/upload/upload-page.html.twig';

    const TYPE_IMAGES                   = 'images';
    const TYPE_FILES                    = 'files';

    const KEY_SUBDIRECTORY_NEW_NAME     = 'subdirectory_new_name';
    const KEY_SUBDIRECTORY_CURRENT_NAME = 'subdirectory_current_name';

    const KEY_SUBDIRECTORY_NAME         = 'subdirectory_name';

    const KEY_UPLOAD_TYPE               = 'upload_type';

    const DEFAULT_KEY_IS_MISSING        = 'Required key is missing in request';

    const UPLOAD_TYPES = [
        self::TYPE_IMAGES => self::TYPE_IMAGES,
        self::TYPE_FILES  => self::TYPE_FILES
    ];

    /**
     * @var FileUploader $fileUploader
     */
    private $fileUploader;

    /**
     * @var FilesHandler $filesHandler
     */
    private $filesHandler;

    /**
     * @var Application $app
     */
    private $app;

    /**
     * @var DirectoriesHandler $directoriesHandler
     */
    private $directoriesHandler;

    public function __construct(FileUploader $fileUploader, FilesHandler $filesHandler, DirectoriesHandler $directoriesHandler, Application $app) {
        $this->fileUploader         = $fileUploader;
        $this->app                  = $app;
        $this->filesHandler         = $filesHandler;
        $this->directoriesHandler   = $directoriesHandler;
    }

    /**
     * @Route("/upload/", name="upload")
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function displayUploadPage(Request $request) {
        $this->sendData($request);
        return $this->renderTemplate(false);
    }

    /**
     * @Route("/upload/send", name="upload_send")
     * @param Request $request
     * @return JsonResponse
     * @throws \Exception
     */
    public function sendData(Request $request){
        $response = $this->handleFileUpload($request);

        $data = [
            'template'       => $this->renderTemplate(true)->getContent(),
            'message'        => $response->getContent(),
            'status_code'    => $response->getStatusCode(),
        ];

        if( $response->getStatusCode() === 200 ){
            $flashType = 'success';
        }else{
            $flashType = 'danger';
        }

        $this->addFlash($flashType, $response->getContent());
        return new JsonResponse($data);
    }

    /**
     * @param $ajax_render
     * @return Response
     * @throws \Exception
     */
    private function renderTemplate($ajax_render)
    {
        $upload_max_filesize = preg_replace("/[^0-9]/","",ini_get('upload_max_filesize'));
        $post_max_size       = preg_replace("/[^0-9]/","",ini_get('post_max_size'));

        $max_upload_size_mb  = ( $post_max_size < $upload_max_filesize ? $post_max_size : $upload_max_filesize);

        $form = $this->getUploadForm();

        $data = [
            'ajax_render'           => $ajax_render,
            'form'                  => $form->createView(),
            'max_upload_size_mb'    => $max_upload_size_mb
        ];

        return $this->render(static::UPLOAD_PAGE_TWIG_TEMPLATE, $data);

    }
    /**
     * @param string $uploadType
     * @param bool $namesAsKeysAndValues
     * @return array
     * @throws \Exception
     */
    public static function getSubdirectoriesForUploadType(string $uploadType, $namesAsKeysAndValues = false)
    {
        $subdirectories = [];
        $finder         = new Finder();

        $targetDirectory = static::getTargetDirectoryForUploadType($uploadType);

        $finder->directories()->in($targetDirectory);

        foreach($finder as $directory){
            $subdirectories[] = $directory->getFilename();
        }

        if($namesAsKeysAndValues){
            $subdirectories = array_combine(
                array_values($subdirectories),
                array_values($subdirectories)
            );
        }

        return $subdirectories;
    }

    /**
     * @param bool $groupedByTypes
     * @return array
     * @throws \Exception
     */
    public static function getSubdirectoriesForAllUploadTypes($groupedByTypes = false){

        $subdirectories = [];

        if( !$groupedByTypes ){
            foreach(static::UPLOAD_TYPES as $upload_type){
                $subdirectories = array_merge($subdirectories, static::getSubdirectoriesForUploadType($upload_type, true) );
            }
        }else{
            foreach(static::UPLOAD_TYPES as $upload_type){
                $subdirectories[$upload_type] = static::getSubdirectoriesForUploadType($upload_type, true);
            }
        }

        return $subdirectories;
    }

    /**
     * @param string $uploadType
     * @return mixed
     * @throws \Exception
     */
    public static function getTargetDirectoryForUploadType(string $uploadType){

        switch ($uploadType) {
            case FileUploadController::TYPE_FILES:
                $targetDirectory = Env::getFilesUploadDir();
                break;
            case FileUploadController::TYPE_IMAGES:
                $targetDirectory = Env::getImagesUploadDir();
                break;
            default:
                throw new \Exception('This type is not allowed');
        }

        return $targetDirectory;
    }

    /**
     * @param string $targetDirectory
     * @param string $subdirectory_name
     * @return bool
     */
    public static function isSubdirectoryForTypeExisting(string $targetDirectory, string $subdirectory_name): bool {
        $subdirectory_path = static::getSubdirectoryPath($targetDirectory, $subdirectory_name);
        return file_exists($subdirectory_path);
    }

    /**
     * @param string $targetDirectory
     * @param string $subdirectory_name
     * @return string
     */
    public static function getSubdirectoryPath(string $targetDirectory, string $subdirectory_name){
        return $targetDirectory . '/' . $subdirectory_name;
    }

    /**
     * @return \Symfony\Component\Form\FormInterface
     * @throws \Exception
     */
    private function getUploadForm(){
        $subdirectories         = static::getSubdirectoriesForAllUploadTypes();
        $grouped_subdirectories = static::getSubdirectoriesForAllUploadTypes(true);

        return $this->createForm(UploadFormType::class, null, [
            'subdirectories'         => $subdirectories,
            'grouped_subdirectories' => $grouped_subdirectories
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    private function handleFileUpload(Request $request) {
        $form = $this->getUploadForm();
        $form->handleRequest($request);

        $response = new Response('No files were uploaded');

        if ($form->isSubmitted() && $form->isValid()) {

            # This strange solution is needed for file upload case - datalistType is not perfect

            $modified_form_data = $request->request->get('upload_form');
            $original_form_data = $form->getData();

            $subdirectory       = $modified_form_data[DirectoriesHandler::SUBDIRECTORY_KEY];
            $upload_type        = $modified_form_data[static::KEY_UPLOAD_TYPE];
            $uploadedFiles      = $original_form_data[FilesHandler::FILE_KEY];

            foreach ($uploadedFiles as $uploadedFile) {
                $response = $this->fileUploader->upload($uploadedFile, $request, $upload_type, $subdirectory);
            }

        }

        return $response;
    }

}