<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    public function store(Request $request)
    {
        $phone = $request->phone;

        if (!str_starts_with($phone, '+966')) {
            $phone = '+966' . ltrim($phone, '0');
        }
        $request->merge(['phone' => $phone]);

        $validator = Validator::make($request->all(), [
            'name'       => 'required|string|max:255',
            'email'      => 'required|email',
            'phone'      => 'required|string|regex:/^\+9665\d{8}$/',
            'subject'    => 'required|string|max:255',
            'message'    => 'required|string',
            'program_id' => 'nullable|exists:programs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid data',
                'errors' => $validator->errors()
            ], 400);
        }

        $phone = $request->phone;
        // +966512345678|0512345678 => after 5 exist 8 numbers
        if (!preg_match('/^(?:\+9665\d{8}|05\d{8})$/', $phone)) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.phone_not_valid'),
            ], 200);
        }

        $contact = new Contact();
        $name = strip_tags($request->name);
        if (empty($name)) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.name_not_valid'),
            ], 200);
        }
        $contact->name   = $name;
        $contact->email  = $request->email;
        $contact->phone  = $request->phone;
        $contact->status = 0;
        $subject = strip_tags($request->subject);
        if (empty($subject)) {
            return response()->json([
                'success' => false,
                'message' => __('trans.alert.error.subject_not_valid'),
            ], 200);
        }
        $contact->subject = $subject;
        $contact->message = strip_tags($request->message);
        $contact->program_id = $request->program_id;
        $contact->save();

        return response()->json([
            'success' => true,
            'message' => __('trans.alert.success.done_create'),
        ], 200);
    }
}
