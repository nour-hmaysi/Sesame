<?php

namespace App\Http\Controllers;
use App\Mail\CustomEmail;
use App\Mail\Email;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use GuzzleHttp\Client;

use Dompdf\Dompdf ;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmailController extends Controller
{
    public function showEmailTemplateForm(Request $request)
    {
        // Retrieve input data
        $contact = $request->input('contact_id');
        $pdfUrl = $request->input('pdf_url');
        $invId = $request->input('inv_id');
        $invTypeId = $request->input('inv_type_id');
        $emailBody = $request->input('email_body');
        $emailSubject = $request->input('email_subject');

        session()->flash('pdfUrl', $pdfUrl);
        session()->flash('emailBody', $emailBody);
        session()->flash('emailSubject', $emailSubject);
        session()->flash('contact', $contact);
        session()->flash('inv_id', $invId);
        session()->flash('inv_type_id', $invTypeId);

        return response()->json([
            'status' => 'success',
            'redirect_url' => route('EmailController.index')
        ]);

    }

    public function index()
    {
        $pdfUrl = session('pdfUrl');
        $emailBody = session('emailBody');
        $emailSubject = session('emailSubject');
        $contact = session('contact');
        $invId = session('inv_id');
        $invTypeId = session('inv_type_id');

        return view('pages.email.compose', compact('pdfUrl', 'emailBody', 'emailSubject', 'contact', 'invId', 'invTypeId'));
    }
//    public function sendCustomEmail()
//    {
//        $body = "<p>This is the dynamic content for the email body.</p>";
//        $subcopy = "This is additional information.";
//        $subject = "email subject.";
//
//        $fromEmail = 'me@gmail.com';
//        $fromName = 'My Name';
//
//        $toEmail = 'recipient@example.com';
//
//        $pdfUrl = 'https://petrikor.agency/semsom/receivable/statement/1?startDate=2024-01-01&endDate=2024-12-31';
//        $attachments = []; // Initialize the attachments array
//
//        // Check if the PDF URL is provided and retrieve the content
//        if ($pdfUrl) {
//            $pdfContent = @file_get_contents($pdfUrl); // Suppress errors with @
//            if ($pdfContent === false) {
//                return response()->json(['error' => 'Unable to retrieve PDF from the provided URL.'], 400);
//            }
//
//            // Save the PDF content to a temporary file
//            $pdfPath = storage_path('app/public/temp.pdf');
//            file_put_contents($pdfPath, $pdfContent);
//            $attachments[] = $pdfPath; // Add the path to the attachments array
//        }
//
//        Mail::to($toEmail)->send(new CustomEmail($body, $subcopy, $subject, $fromEmail, $fromName, $attachments));
//
//        return "Email sent successfully!";
//    }
    public function sendCustomEmail()
    {
//        $filePath = public_path('system/attachments/document.pdf');
//        $name = "Funny Coder";
//
//        // Fixed syntax error in the email address
//        Mail::to('testreceiver@gmail.com')->send(new Email($name, $filePath));
//
//
//        return 's';
        $body = 'This is the email body....';
        $subcopy = 'This is the email subcopy.....';

        $body = "<p>This is the dynamic content for the email body.</p>";
        $subcopy = "This is additional information.";
        $subject = "email subject.saas";

        $fromEmail = 'me@gmail.com';
        $fromName = 'My Name';
        $toEmail = 'recipient@example.com';
        $attachments = $pdfPath = '';

        $pdfUrl = 'https://petrikor.agency/semsom/receivable/statement/1?startDate=2024-01-01&endDate=2024-12-31';

        if ($pdfUrl) {


            $options = new Options();
            $options->set('defaultFont', 'Courier');
            $dompdf = new Dompdf($options);


            $content = @file_get_contents($pdfUrl);
            if ($content === false) {
                return response()->json(['error' => 'Could not fetch the URL content.'], 500);
            }


            $dompdf->loadHtml($content);


            $dompdf->setPaper('A4', 'portrait');


            $dompdf->render();


            $pdfOutput = $dompdf->output();

            $filename = 'document-'.Str::random(10).'.pdf';

            $pdfPath = public_path('system/attachments/'.$filename);

            file_put_contents($pdfPath, $pdfOutput);
            $attachments = $pdfPath;

        } else {
            $attachments = '';
        }
        Mail::to('recipient@example.com')->send(new CustomEmail($body, $fromEmail, $fromName, $attachments,$subcopy, $subject));
        if ($pdfPath) {
            unlink($pdfPath); // Delete the temporary file
        }
        return "Email sent successfully!";
    }
    public function sendEmail(Request $request)
    {

        $fromEmail = 'semsom@gmail.com';
        $fromName = 'semsom';

        $email = $request->email;
        $pdfUrl = $request->pdf_url;
        $invId = $request->inv_id;
        $invTypeId = $request->inv_type_id;
        $emailSubject = $request->subject;
        $emailBody = $request->body;
        $pdfPath = $subcopy = '';
        if($pdfUrl){
            $options = new Options();
            $options->set('defaultFont', 'Courier');
            $dompdf = new Dompdf($options);
            $content = @file_get_contents($pdfUrl);
            if ($content === false) {
                return response()->json(['error' => 'Could not fetch the URL content.'], 500);
            }
            $dompdf->loadHtml($content);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $pdfOutput = $dompdf->output();
            $filename = 'document-'.dateOfToday().'.pdf';
            $pdfPath = public_path('system/attachments/'.$filename);
            file_put_contents($pdfPath, $pdfOutput);
            $attachments = $pdfPath;
        }

        if ($invId > 0 && $invTypeId > 0) {
            // Get status
            $status = getStatusOfInv($invId, $invTypeId);

            if ($status == 1) {
                // Update status
                updateStatusOfInv($invId, $invTypeId);
            }
        }


//        Mail::send([], [],  function ($message) use ($pdfPath, $emailSubject, $emailBody, $email, $fromEmail, $fromName, $pdfUrl) {
//            $message->to($email)
//                ->subject($emailSubject)
//                ->html($emailBody)
//                ->from($fromEmail, $fromName);
//            if ($pdfUrl) {
//                $message->attachFromPath($pdfPath, 'AttachedDocument.pdf', 'application/pdf');
//            }
//        });

        Mail::to($email)->send(new CustomEmail($emailBody, $fromEmail, $fromName, $attachments,$subcopy, $emailSubject));


        if ($pdfPath) {
            unlink($pdfPath); // Delete the temporary file
        }
        return back()->with('success', 'Email sent successfully!');
    }
}
