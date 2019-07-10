<?php

namespace App\Http\Controllers\Voyager;

use Illuminate\Http\Request;
use TCG\Voyager\Http\Controllers\VoyagerBaseController;
use TCG\Voyager\Facades\Voyager;
use QR_Code\QR_Code;
use Illuminate\Support\Facades\Input;
use App\Qrcode;

class QRCodeController extends VoyagerBaseController
{
    //***************************************
    //                _____
    //               |  __ \
    //               | |__) |
    //               |  _  /
    //               | | \ \
    //               |_|  \_\
    //
    //  Read an item of our Data Type B(R)EAD
    //
    //****************************************

    public function show(Request $request, $id)
    {
        $slug = $this->getSlug($request);

        $dataType = Voyager::model('DataType')->where('slug', '=', $slug)->first();

        $isSoftDeleted = false;

        if (strlen($dataType->model_name) != 0) {
            $model = app($dataType->model_name);

            // Use withTrashed() if model uses SoftDeletes and if toggle is selected
            if ($model && in_array(SoftDeletes::class, class_uses($model))) {
                $model = $model->withTrashed();
            }
            if ($dataType->scope && $dataType->scope != '' && method_exists($model, 'scope'.ucfirst($dataType->scope))) {
                $model = $model->{$dataType->scope}();
            }
            $dataTypeContent = call_user_func([$model, 'findOrFail'], $id);
            if ($dataTypeContent->deleted_at) {
                $isSoftDeleted = true;
            }
        } else {
            // If Model doest exist, get data from table name
            $dataTypeContent = DB::table($dataType->name)->where('id', $id)->first();
        }

        // Replace relationships' keys for labels and create READ links if a slug is provided.
        $dataTypeContent = $this->resolveRelations($dataTypeContent, $dataType, true);

        // If a column has a relationship associated with it, we do not want to show that field
        $this->removeRelationshipField($dataType, 'read');

        // Check permission
        $this->authorize('read', $dataTypeContent);

        // Check if BREAD is Translatable
        $isModelTranslatable = is_bread_translatable($dataTypeContent);

        $view = 'voyager::bread.read';

        if (view()->exists("voyager::$slug.read")) {
            $view = "voyager::$slug.read";
        }
        
        $qrcodeImage = Qrcode::findOrFail($id)->qrcode_image;

        return Voyager::view($view, compact('dataType', 'dataTypeContent', 'isModelTranslatable', 'isSoftDeleted', 'qrcodeImage'));
    }


    // POST BR(E)AD
    public function update(Request $request, $id)
    {
        $slug = $this->getSlug($request);

        $dataType = Voyager::model('DataType')->where('slug', '=', $slug)->first();

        // Compatibility with Model binding.
        $id = $id instanceof Model ? $id->{$id->getKeyName()} : $id;

        $model = app($dataType->model_name);
        if ($dataType->scope && $dataType->scope != '' && method_exists($model, 'scope'.ucfirst($dataType->scope))) {
            $model = $model->{$dataType->scope}();
        }
        if ($model && in_array(SoftDeletes::class, class_uses($model))) {
            $data = $model->withTrashed()->findOrFail($id);
        } else {
            $data = call_user_func([$dataType->model_name, 'findOrFail'], $id);
        }

        // Check permission
        $this->authorize('edit', $data);
        

        // Validate fields with ajax
        $val = $this->validateBread($request->all(), $dataType->editRows, $dataType->name, $id)->validate();

        // Create QR Code
        $request = $this->createQRCode($request);

        // Insert or Update Data
        $this->insertUpdateData($request, $slug, $dataType->editRows, $data);


        return redirect()
        ->route("voyager.{$dataType->slug}.index")
        ->with([
            'message'    => __('voyager::generic.successfully_updated')." {$dataType->display_name_singular}",
            'alert-type' => 'success',
        ]);
    }


    /**
     * POST BRE(A)D - Store data.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $slug = $this->getSlug($request);

        $dataType = Voyager::model('DataType')->where('slug', '=', $slug)->first();

        // Check permission
        $this->authorize('add', app($dataType->model_name));

        // Validate fields with ajax
        $val = $this->validateBread($request->all(), $dataType->addRows)->validate();
        // Create QR Code
        $request = $this->createQRCode($request);
        // Insert or Update Data
        $data = $this->insertUpdateData($request, $slug, $dataType->addRows, new $dataType->model_name());

        

        return redirect()
        ->route("voyager.{$dataType->slug}.index")
        ->with([
                'message'    => __('voyager::generic.successfully_added_new')." {$dataType->display_name_singular}",
                'alert-type' => 'success',
            ]);
    }

    // Create QR Code
    public function createQRCode(Request $request){
        $id = $request->id;
        $firstName = $request->first_name;
        $lastName = $request->last_name;
        $organization = $request->organization;
        $job = $request->job;
        $address = $request->address;
        $phoneNumber = $request->phone_number;
        $email = $request->email;
        $url = $request->url;

        $qrcode_data = <<<DATA
BEGIN:VCARD
VERSION:3.0
N:;$firstName $lastName
FN:$firstName $lastName
ORG:$organization
TITLE:$job
ADR:$address
TEL:$phoneNumber
EMAIL:$email
URL:$url
ID:$phoneNumber
END:VCARD     
DATA;

        // Path QR Code Image
        $path = public_path('storage'.DIRECTORY_SEPARATOR.'qrcodes'.DIRECTORY_SEPARATOR.$request->phone_number.'.png');
        
        //Create and Save QR Code
        QR_Code::png($qrcode_data,$path);

        // Change value qrcode_image
        $path_img = 'qrcodes/'.$request->phone_number.'.png';
        Input::merge(['qrcode_image' => $path_img]);
        return $request;
    }

    function responseInfoById(Request $request){
        if($user = Qrcode::where('phone_number', '=', $request->id)->first()){
            return response($user,200);
        }
        else return response("Không tìm thấy kết quả",404);
    }

    function get_string_between($string, $start, $end){
        $string = ' ' . $string;
        $ini = strpos($string, $start);
        if ($ini == 0) return '';
        $ini += strlen($start);
        $len = strpos($string, $end, $ini) - $ini;
        return substr($string, $ini, $len);
    }    
    

    
}
