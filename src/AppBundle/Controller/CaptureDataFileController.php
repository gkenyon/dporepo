<?php

namespace AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Doctrine\DBAL\Driver\Connection;

use AppBundle\Controller\RepoStorageHybridController;
use PDO;

use AppBundle\Form\CaptureDataFileForm;
use AppBundle\Entity\CaptureDataFile;

// Custom utility bundle
use AppBundle\Utils\AppUtilities;
use AppBundle\Service\RepoUserAccess;

class CaptureDataFileController extends Controller
{
    /**
     * @var object $u
     */
    public $u;

    private $repo_storage_controller;
    private $repo_user_access;

    /**
     * Constructor
     * @param object  $u  Utility functions object
     */
    public function __construct(AppUtilities $u, Connection $conn)
    {
        // Usage: $this->u->dumper($variable);
        $this->u = $u;
        $this->repo_storage_controller = new RepoStorageHybridController($conn);
        $this->repo_user_access = new RepoUserAccess($conn);
    }

    /**
     * @Route("/admin/datatables_browse_capture_data_files", name="capture_data_files_browse_datatables", methods="POST")
     *
     * @param Request $request
     * @return JsonResponse The query result in JSON
     */
    public function datatablesBrowse(Request $request)
    {

      $req = $request->request->all();
      $search = !empty($req['search']['value']) ? $req['search']['value'] : false;
      $sort_field = $req['columns'][ $req['order'][0]['column'] ]['data'];
      $sort_order = $req['order'][0]['dir'];
      $start_record = !empty($req['start']) ? $req['start'] : 0;
      $stop_record = !empty($req['length']) ? $req['length'] : 20;
      $parent_id = isset($req['parent_id']) ? $req['parent_id'] : 0;

      $query_params = array(
        'sort_field' => $sort_field,
        'sort_order' => $sort_order,
        'start_record' => $start_record,
        'stop_record' => $stop_record,
        'parent_id' => $parent_id,
      );
      if ($search) {
        $query_params['search_value'] = $search;
      }

      $data = $this->repo_storage_controller->execute('getDatatableCaptureDataFile', $query_params);

      return $this->json($data);
    }

    /**
     * Matches /admin/capture_data_file/manage/*
     *
     * @Route("/admin/capture_data_file/add/{parent_id}", name="capture_data_files_add", methods={"GET","POST"}, defaults={"id" = null})
     * @Route("/admin/capture_data_file/manage/{id}", name="capture_data_files_manage", methods={"GET","POST"}, defaults={"parent_id" = null})
     *
     * @param Connection $conn
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response Redirect or render
     */
    function formView(Connection $conn, Request $request)
    {
        $data = new CaptureDataFile();
        $post = $request->request->all();
        $parent_id = !empty($request->attributes->get('parent_id')) ? $request->attributes->get('parent_id') : false;
        $id = !empty($request->attributes->get('id')) ? $request->attributes->get('id') : false;

        // If no parent_id is passed, throw a createNotFoundException (404).
        if(!$parent_id && !$id) throw $this->createNotFoundException('The record does not exist');

        // Get the parent project ID.
        $parent_records = $this->repo_storage_controller->execute('getParentRecords', array(
          'base_record_id' => $parent_id ? $parent_id : $id,
          'record_type' => $parent_id ? 'capture_dataset' : 'uv_map_with_model_id',
        ));

        // Check user's permissions.
        // If parent records exist and there's no parent_id, the record is being edited. Otherwise, the record is being added.
        if(is_array($parent_records) && array_key_exists('project_id', $parent_records) && !$parent_id) {
          $permission = 'edit_project_details';
        } else {
          $permission = 'create_project_details';
        }

        $username = $this->getUser()->getUsernameCanonical();
        $access = $this->repo_user_access->get_user_access($username, $permission, $parent_records['project_id']);
        if(!array_key_exists('project_ids', $access) || !isset($access['project_ids'])) {
          $response = new Response();
          $response->setStatusCode(403);
          return $response;
        }

        // Retrieve data from the database, and if the record doesn't exist, throw a createNotFoundException (404).
        if(!empty($id) && empty($post)) {
            $rec = $this->repo_storage_controller->execute('getRecordById', array(
            'record_type' => 'capture_data_file',
            'record_id' => $id));
            if(isset($rec)) {
              $data = (object)$rec;
            }
        }
        if(!$data) throw $this->createNotFoundException('The record does not exist');

        // Add the parent_id to the $data object
        if(false !== $parent_id) {
          $data->capture_data_element_id = $parent_id;
        }

        // Create the form
        $form = $this->createForm(CaptureDataFileForm::class, $data);
        
        // Handle the request
        $form->handleRequest($request);
        
        // If form is submitted and passes validation, insert/update the database record.
        if ($form->isSubmitted() && $form->isValid()) {

            $data = $form->getData();
            $id = $this->repo_storage_controller->execute('saveRecord', array(
              'base_table' => 'capture_data_file',
              'record_id' => $id,
              'user_id' => $this->getUser()->getId(),
              'values' => (array)$data
            ));

            $this->addFlash('message', 'Record successfully updated.');
            return $this->redirect('/admin/dataset_element/manage/' . $data->capture_data_element_id);

        }

        return $this->render('datasetElements/capture_data_file_form.html.twig', array(
          'page_title' => !empty($id) ? 'Capture Data File: ' . $data->capture_data_file_name : 'Create Capture Data File',
          'data' => $data,
          'is_favorite' => $this->getUser()->favorites($request, $this->u, $conn),
          'form' => $form->createView(),
          'current_tab' => 'resources'
        ));
    }

    /**
     * @Route("/admin/capture_data_file/delete", name="capture_data_files_remove_records", methods={"GET"})
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response Redirect or render
     */
    public function deleteMultiple(Request $request)
    {
      $username = $this->getUser()->getUsernameCanonical();
      $access = $this->repo_user_access->get_user_access_any($username, 'create_edit_lookups');

      if(!array_key_exists('permission_name', $access) || empty($access['permission_name'])) {
        $response = new Response();
        $response->setStatusCode(403);
        return $response;
      }

        if(!empty($request->query->get('ids'))) {

            // Create the array of ids.
            $ids_array = explode(',', $request->query->get('ids'));

            // Loop thorough the ids.
            foreach ($ids_array as $key => $id) {
              // Run the query against a single record.
              $ret = $this->repo_storage_controller->execute('markRecordInactive', array(
                'record_type' => 'capture_data_file',
                'record_id' => $id,
                'user_id' => $this->getUser()->getId(),
              ));
            }

            $this->addFlash('message', 'Records successfully removed.');

        } else {
            $this->addFlash('message', 'Missing data. No records removed.');
        }

        $referer = $request->headers->get('referer');
        return $this->redirect($referer);
    }

}
