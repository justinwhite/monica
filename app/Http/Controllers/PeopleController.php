<?php

namespace App\Http\Controllers;

use Auth;
use App\Kid;
use App\Gift;
use App\Note;
use App\Task;
use App\Event;
use Validator;
use App\Contact;
use App\Country;
use App\Activity;
use App\Reminder;
use Carbon\Carbon;
use App\ActivityType;
use App\ReminderType;
use App\Http\Requests;
use App\SignificantOther;
use App\ActivityTypeGroup;
use App\Helpers\DateHelper;
use App\Jobs\ResizeAvatars;
use Illuminate\Http\Request;
use App\Helpers\RandomHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Input;

class PeopleController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $sort = Input::get('sort');

        switch ($sort) {
            case 'firstnameAZ':
            case 'firstnameZA':
            case 'lastnameAZ':
            case 'lastnameZA':
                Auth::user()->updateContactViewPreference($sort);
                break;
        }

        if (Auth::user()->contacts_sort_order == 'firstnameAZ') {
            $contacts = Contact::where('account_id', Auth::user()->account_id)
                              ->orderBy('first_name', 'asc')
                              ->get();
        }

        if (Auth::user()->contacts_sort_order == 'firstnameZA') {
            $contacts = Contact::where('account_id', Auth::user()->account_id)
                              ->orderBy('first_name', 'desc')
                              ->get();
        }

        if (Auth::user()->contacts_sort_order == 'lastnameAZ') {
            $contacts = Contact::where('account_id', Auth::user()->account_id)
                              ->orderBy('last_name', 'asc')
                              ->get();
        }

        if (Auth::user()->contacts_sort_order == 'lastnameZA') {
            $contacts = Contact::where('account_id', Auth::user()->account_id)
                              ->orderBy('last_name', 'desc')
                              ->get();
        }

        $data = [
            'contacts' => $contacts,
        ];

        return view('people.index', $data);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('people.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|max:255',
            'gender' => 'required',
        ]);

        if ($validator->fails()) {
            return back()
                ->withInput()
                ->withErrors($validator);
        }

        $contact = new Contact;
        $contact->account_id = Auth::user()->account_id;
        $contact->gender = $request->input('gender');
        $contact->first_name = ucfirst($request->input('first_name'));

        if (! empty($request->input('last_name'))) {
            $contact->last_name = ucfirst($request->input('last_name'));
        }

        $contact->save();

        return redirect()->route('people.show', ['id' => $contact->id]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $data = [
            'contact' => $contact,
        ];

        return view('people.profile', $data);
    }

    /**
     * Display the Edit people's view.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $data = [
            'contact' => $contact,
        ];

        return view('people.dashboard.edit', $data);
    }

    /**
     * Update the identity and address of the People object.
     * @param  Request $request
     * @param  int  $peopleId
     * @return Response
     */
    public function update(Request $request, $contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $validator = Validator::make($request->all(), [
            'firstname' => 'required|max:255',
            'gender' => 'required',
            'file' => 'max:10240',
        ]);

        if ($validator->fails()) {
            return back()
                ->withInput()
                ->withErrors($validator);
        }

        $contact->gender = $request->input('gender');
        $contact->first_name = $request->input('firstname');

        if ($request->input('lastname') != '') {
            $contact->last_name = $request->input('lastname');
        } else {
            $contact->last_name = null;
        }

        if ($request->file('avatar') != '') {
            $contact->has_avatar = 'true';
            $contact->avatar_file_name = $request->file('avatar')->store('avatars', 'public');
        }

        if ($request->input('email') != '') {
            $contact->email = encrypt($request->input('email'));
        } else {
            $contact->email = null;
        }

        if ($request->input('phone') != '') {
            $contact->phone_number = encrypt($request->input('phone'));
        } else {
            $contact->phone_number = null;
        }

        if ($request->input('street') != '') {
            $contact->street = encrypt($request->input('street'));
        } else {
            $contact->street = null;
        }

        if ($request->input('postalcode') != '') {
            $contact->postal_code = encrypt($request->input('postalcode'));
        } else {
            $contact->postal_code = null;
        }

        if ($request->input('province') != '') {
            $contact->province = encrypt($request->input('province'));
        } else {
            $contact->province = null;
        }

        if ($request->input('city') != '') {
            $contact->city = encrypt($request->input('city'));
        } else {
            $contact->city = null;
        }

        $contact->country_id = $request->input('country');

        if ($request->input('birthdateApproximate') == 'true') {
            $age = $request->input('age');
            $year = Carbon::now()->subYears($age)->year;
            $birthdate = Carbon::createFromDate($year, 1, 1);
            $contact->birthdate = $birthdate;
        } else {
            $birthdate = Carbon::createFromFormat('Y-m-d', $request->input('specificDate'));
            $contact->birthdate = $birthdate;
        }

        $contact->save();

        $request->session()->flash('success', trans('people.information_edit_success'));

        dispatch(new ResizeAvatars($contact));

        return redirect('/people/'.$contact->id);
    }

    /**
     * Delete the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function delete($id)
    {
        $people = People::findOrFail($id);

        if ($people->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $people->deleted_at = Carbon::now();
        $people->save();

        // soft deleting all the reminders for this people
        $reminders = Reminder::where('people_id', $people->id)
                              ->get();

        foreach ($reminders as $reminder) {
            $reminder->deleted_at = Carbon::now();
            $reminder->save();
        }

        // soft deleting all contact objects
        $contacts = Contact::where('people_id', $people->id)
                              ->get();

        foreach ($contacts as $contact) {
            $contact->deleted_at = Carbon::now();
            $contact->save();
        }

        // soft deleting the kids
        $kids = Kid::where('child_of_people_id', $people->id)
                              ->get();

        foreach ($kids as $kid) {
            $kid->deleted_at = Carbon::now();
            $kid->save();
        }

        // soft deleting the notes
        $notes = Note::where('people_id', $people->id)->get();
        foreach ($notes as $note) {
            $note->deleted_at = Carbon::now();
            $note->save();
        }

        // soft deleting the significant_others
        $signficantOthers = SignificantOther::where('people_id', $people->id)->get();
        foreach ($signficantOthers as $signficantOther) {
            $signficantOther->deleted_at = Carbon::now();
            $signficantOther->save();
        }

        // soft deleting the activities
        $activities = Activity::where('people_id', $people->id)->get();
        foreach ($activities as $activity) {
            $activity->deleted_at = Carbon::now();
            $activity->save();
        }

        return redirect()->route('people.index');
    }

    /**
     * Show the Edit food preferencies view.
     * @param  Request $request
     * @param  [type]  $peopleId integer
     * @return View
     */
    public function editFoodPreferencies(Request $request, $contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $data = [
            'contact' => $contact,
        ];

        return view('people.dashboard.food-preferencies.edit', $data);
    }

    /**
     * Save the food preferencies.
     * @param int $id
     * @return
     */
    public function updateFoodPreferencies(Request $request, $contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $food = $request->input('food');

        if ($food != '') {
            $contact->updateFoodPreferencies($food);
        } else {
            $contact->updateFoodPreferencies(null);
        }

        $request->session()->flash('success', trans('people.food_preferencies_add_success'));

        return redirect('/people/'.$contact->id);
    }

    /**
     * Display the activities page.
     * @param  int $id ID of the People object
     * @return view
     */
    public function activities($contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $data = [
            'contact' => $contact,
        ];

        return view('people.activities.index', $data);
    }

    /**
     * Show the Add activity screen.
     * @param int $id ID of the people object
     */
    public function addActivity($contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $data = [
            'contact' => $contact,
        ];

        return view('people.activities.add', $data);
    }

    /**
     * Store the activity for the People object.
     * @param int $id
     * @return
     */
    public function storeActivity(Request $request, $contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $activityTypeId = $request->input('activityType');
        $description = $request->input('comment');
        $dateItHappened = Carbon::createFromFormat('Y-m-d', $request->input('specific_date'));

        $activity = new Activity;
        $activity->account_id = $contact->account_id;
        $activity->contact_id = $contact->id;
        $activity->activity_type_id = $activityTypeId;

        if ($description == null) {
            $activity->description = null;
        } else {
            $activity->description = encrypt($description);
        }
        $activity->date_it_happened = $dateItHappened;
        $activity->save();

        $request->session()->flash('success', trans('people.activities_add_success'));

        return redirect('/people/'.$contact->id);
    }

    /**
     * Edit the activity.
     */
    public function editActivity($contactId, $activityId)
    {
        $contact = Contact::findOrFail($contactId);
        $activity = Activity::findOrFail($activityId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        if ($contact->account_id != $activity->account_id) {
            return redirect()->route('people.index');
        }

        if ($contact->id != $activity->contact_id) {
            return redirect()->route('people.index');
        }

        $data = [
            'contact' => $contact,
            'activity' => $activity,
        ];

        return view('people.activities.edit', $data);
    }

    /**
     * Save the updated activity.
     * @param int $id
     * @return
     */
    public function updateActivity(Request $request, $contactId, $activityId)
    {
        $contact = Contact::findOrFail($contactId);
        $activity = Activity::findOrFail($activityId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        if ($contact->account_id != $activity->account_id) {
            return redirect()->route('people.index');
        }

        if ($contact->id != $activity->contact_id) {
            return redirect()->route('people.index');
        }

        $activity->activity_type_id = $request->input('activityType');
        $description = $request->input('comment');
        if ($description == null || $description == '') {
            $activity->description = null;
        } else {
            $activity->description = encrypt($description);
        }
        $activity->date_it_happened = Carbon::createFromFormat('Y-m-d', $request->input('specific_date'));
        $activity->save();

        $request->session()->flash('success', trans('people.activities_update_success'));

        return redirect('/people/'.$contact->id);
    }

    /**
     * Delete the activity.
     * @param int $id
     * @return
     */
    public function deleteActivity(Request $request, $contactId, $activityId)
    {
        $contact = Contact::findOrFail($contactId);
        $activity = Activity::findOrFail($activityId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        if ($contact->account_id != $activity->account_id) {
            return redirect()->route('people.index');
        }

        if ($contact->id != $activity->contact_id) {
            return redirect()->route('people.index');
        }

        $activity->delete();

        $request->session()->flash('success', trans('people.activities_delete_success'));

        return redirect('/people/'.$contact->id);
    }

    /**
     * Display the reminders page.
     * @param  int $id ID of the People object
     *
     * @return view
     */
    public function reminders($contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $data = [
            'contact' => $contact,
        ];

        return view('people.reminders.index', $data);
    }

    /**
     * Show the Add reminder screen.
     * @param int $id ID of the people object
     */
    public function addReminder($contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $data = [
            'contact' => $contact,
        ];

        return view('people.reminders.add', $data);
    }

    /**
     * Add a reminder.
     * @param Request $request
     * @param int
     */
    public function storeReminder(Request $request, $contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $reminderIsPredefined = $request->input('reminderIsPredefined');
        $reminderPredefinedTypeId = $request->input('reminderPredefinedTypeId');
        $reminderCustomText = $request->input('reminderCustomText');
        $reminderNextExpectedDate = $request->input('reminderNextExpectedDate');

        $frequencyType = $request->input('frequencyType');
        $frequencyRecurringNumber = $request->input('frequencyRecurringNumber');

        $reminderRecurringFrequency = $request->input('reminderRecurringFrequency');
        $comment = $request->input('comment');

        // Validation rules
        if ($reminderIsPredefined == 'false' and $reminderCustomText == '') {
            return back()
              ->withInput()
              ->withErrors(['error' => trans('people.reminders_add_error_custom_text')]);
        }

        // Create the reminder
        $reminder = new Reminder;

        // Set the reminder_type_id and title fields
        if ($reminderIsPredefined == 'true') {
            $reminderType = ReminderType::find($reminderPredefinedTypeId);
            $reminder->reminder_type_id = $reminderPredefinedTypeId;
            $reminder->title = encrypt(trans($reminderType->translation_key).' '.$contact->getFirstName());
        } else {
            $reminder->reminder_type_id = null;
            $reminder->title = encrypt($reminderCustomText);
        }

        if ($comment != '') {
            $reminder->description = encrypt($comment);
        }

        if ($frequencyType == 'once') {
            $reminder->frequency_type = 'one_time';
        } else {
            $reminder->frequency_type = $reminderRecurringFrequency;
            $reminder->frequency_number = $frequencyRecurringNumber;
        }

        $reminder->next_expected_date = $reminderNextExpectedDate;
        $reminder->account_id = $contact->account_id;
        $reminder->contact_id = $contact->id;
        $reminder->save();

        $request->session()->flash('success', trans('people.reminders_create_success'));

        return redirect('/people/'.$contact->id);
    }

    /**
     * Delete the reminder.
     */
    public function deleteReminder(Request $request, $contactId, $reminderId)
    {
        $contact = Contact::findOrFail($contactId);
        $reminder = Reminder::findOrFail($reminderId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        if ($reminder->contact_id != $contact->id) {
            return redirect()->route('people.index');
        }

        $reminder->delete();

        $request->session()->flash('success', trans('people.reminders_delete_success'));

        return redirect('/people/'.$contact->id);
    }

    /**
     * Display the tasks page.
     * @param  int $id ID of the People object
     * @return view
     */
    public function tasks($contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $data = [
            'contact' => $contact,
        ];

        return view('people.tasks.index', $data);
    }

    /**
     * Show the Add task view.
     * @param Request $request
     * @param int
     */
    public function addTask($contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $data = [
            'contact' => $contact,
        ];

        return view('people.tasks.add', $data);
    }

    /**
     * Add a task.
     * @param Request $request
     * @param int
     */
    public function storeTask(Request $request, $contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|max:255',
        ]);

        if ($validator->fails()) {
            return back()
                ->withInput()
                ->withErrors($validator);
        }

        $task = new Task;
        $task->account_id = $contact->account_id;
        $task->contact_id = $contact->id;
        $task->title = encrypt($request->input('title'));

        if ($request->input('comment') != '') {
            $task->description = encrypt($request->input('comment'));
        } else {
            $task->description = null;
        }

        $task->status = 'inprogress';
        $task->save();

        $request->session()->flash('success', trans('people.tasks_add_success'));

        return redirect('/people/'.$contact->id);
    }

    /**
     * Toggle a task between being complete and in progress.
     * @param Request $request
     * @param int
     */
    public function toggleTask(Request $request, $contactId, $taskId)
    {
        $contact = Contact::findOrFail($contactId);
        $task = Task::findOrFail($taskId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        if ($task->contact_id != $contact->id) {
            return redirect()->route('people.index');
        }

        $task->toggle();

        $request->session()->flash('success', trans('people.tasks_complete_success'));

        return redirect('/people/'.$contact->id);
    }

    /**
     * Mark the task as deleted.
     * @param  Request $request
     * @param  int  $peopleId
     * @param  int $taskId
     */
    public function deleteTask(Request $request, $contactId, $taskId)
    {
        $contact = Contact::findOrFail($contactId);
        $task = Task::findOrFail($taskId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        if ($task->contact_id != $contact->id) {
            return redirect()->route('people.index');
        }

        $task->delete();

        $request->session()->flash('success', trans('people.tasks_delete_success'));

        return redirect('/people/'.$contact->id);
    }

    /**
     * Store the note for this people.
     * @param  [type] $peopleId [description]
     * @return [type]           [description]
     */
    public function storeNote(Request $request, $contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $body = $request->input('body');

        $noteId = $contact->addNote($body);

        $request->session()->flash('success', trans('people.notes_add_success'));

        return redirect('/people/'.$contact->id);
    }

    /**
     * Delete the note.
     *
     * @param  Request $request
     * @param  int
     * @param  int
     */
    public function deleteNote(Request $request, $contactId, $noteId)
    {
        $contact = Contact::findOrFail($contactId);
        $note = Note::findOrFail($noteId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        if ($note->contact_id != $contact->id) {
            return redirect()->route('people.index');
        }

        $contact->deleteNote($noteId);

        $request->session()->flash('success', trans('people.notes_delete_success'));

        return redirect('/people/'.$contact->id);
    }

    /**
     * Display the Add Note view.
     *
     * @param int $contactId
     */
    public function addNote($contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $data = [
            'contact' => $contact,
        ];

        return view('people.dashboard.notes.add', $data);
    }

    /**
     * Display the Add Kid view.
     *
     * @param int $contactId
     */
    public function addKid($contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $data = [
            'contact' => $contact,
        ];

        return view('people.dashboard.kids.add', $data);
    }

    /**
     * Add a kid to the database.
     * @param int
     * @return Response
     */
    public function storeKid(Request $request, $contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $name = $request->input('firstname');
        $gender = $request->input('gender');
        $birthdateApproximate = $request->input('birthdateApproximate');

        if ($birthdateApproximate == 'true') {
            $age = $request->input('age');
            $birthdate = null;
        } else {
            $age = null;
            $birthdate = $request->input('specificDate');
        }

        $kidId = $contact->addKid($name, $gender, $birthdateApproximate, $birthdate, $age, Auth::user()->timezone);

        $request->session()->flash('success', trans('people.kids_add_success'));

        return redirect('/people/'.$contact->id);
    }

    /**
     * Show the Edit kid view.
     * @param  int $peopleId
     * @param  int $kidId
     * @return Response
     */
    public function editKid($contactId, $kidId)
    {
        $contact = Contact::findOrFail($contactId);
        $kid = Kid::findOrFail($kidId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        if ($kid->child_of_contact_id != $contact->id) {
            return redirect()->route('people.index');
        }

        $data = [
            'contact' => $contact,
            'kid' => $kid,
        ];

        return view('people.dashboard.kids.edit', $data);
    }

    /**
     * Edit information the kid.
     * @param  int $peopleId
     * @param  int $kidId
     * @return Response
     */
    public function updateKid(Request $request, $contactId, $kidId)
    {
        $contact = Contact::findOrFail($contactId);
        $kid = Kid::findOrFail($kidId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        if ($kid->child_of_contact_id != $contact->id) {
            return redirect()->route('people.index');
        }

        $name = $request->input('firstname');
        $gender = $request->input('gender');
        $birthdateApproximate = $request->input('birthdateApproximate');

        if ($birthdateApproximate == 'true') {
            $age = $request->input('age');
            $birthdate = null;
        } else {
            $age = null;
            $birthdate = $request->input('specificDate');
        }

        $kidId = $contact->editKid($kidId, $name, $gender, $birthdateApproximate, $birthdate, $age, Auth::user()->timezone);

        $request->session()->flash('success', trans('people.kids_update_success'));

        return redirect('/people/'.$contact->id);
    }

    /**
     * Delete a kid.
     * @param  Request $request
     * @param  int  $peopleId
     * @param  int  $kidId
     * @return Redirect
     */
    public function deleteKid(Request $request, $contactId, $kidId)
    {
        $contact = Contact::findOrFail($contactId);
        $kid = Kid::findOrFail($kidId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        if ($kid->child_of_contact_id != $contact->id) {
            return redirect()->route('people.index');
        }

        $contact->deleteKid($kidId);

        $request->session()->flash('success', trans('people.kids_delete_success'));

        return redirect()->route('people.show', ['id' => $contact->id]);
    }

    /**
     * Show the Add significant other view.
     * @param int $peopleId
     */
    public function addSignificantOther($contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $data = [
            'contact' => $contact,
        ];

        return view('people.dashboard.significantother.add', $data);
    }

    /**
     * Add significant other.
     * @param Request $request
     * @param int
     * @return json
     */
    public function storeSignificantOther(Request $request, $contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $firstname = $request->input('firstname');
        $lastname = $request->input('lastname');
        if ($lastname == '') {
            $lastname = null;
        }
        $gender = $request->input('gender');
        $birthdateApproximate = $request->input('birthdateApproximate');

        if ($birthdateApproximate == 'true') {
            $age = $request->input('age');
            $birthdate = null;
        } else {
            $age = null;
            $birthdate = $request->input('specificDate');
        }

        $significantOtherId = $contact->addSignificantOther($firstname, $lastname, $gender, $birthdateApproximate, $birthdate, $age, Auth::user()->timezone);

        $request->session()->flash('success', trans('people.significant_other_add_success'));

        return redirect('/people/'.$contact->id);
    }

    /**
     * Show the Edit significant other view.
     *
     * @param  int $contactId
     * @param  int $significantOtherId
     * @return Response
     */
    public function editSignificantOther($contactId, $significantOtherId)
    {
        $contact = Contact::findOrFail($contactId);
        $significantOther = SignificantOther::findOrFail($significantOtherId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        if ($significantOther->contact_id != $contact->id) {
            return redirect()->route('people.index');
        }

        $data = [
            'contact' => $contact,
        ];

        return view('people.dashboard.significantother.edit', $data);
    }

    /**
     * Update the significant other information.
     *
     * @param  Request $request
     * @param  int  $contactId
     * @param  int  $significantOtherId
     * @return Response
     */
    public function updateSignificantOther(Request $request, $contactId, $significantOtherId)
    {
        $contact = Contact::findOrFail($contactId);
        $significantOther = SignificantOther::findOrFail($significantOtherId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        if ($significantOther->contact_id != $contact->id) {
            return redirect()->route('people.index');
        }

        $firstname = $request->input('firstname');
        $lastname = $request->input('lastname');
        if ($lastname == '') {
            $lastname = null;
        }
        $gender = $request->input('gender');
        $birthdateApproximate = $request->input('birthdateApproximate');

        if ($birthdateApproximate == 'true') {
            $age = $request->input('age');
            $birthdate = null;
        } else {
            $age = null;
            $birthdate = $request->input('specificDate');
        }

        $significantOtherId = $contact->editSignificantOther($significantOther->id, $firstname, $lastname, $gender, $birthdateApproximate, $birthdate, $age, Auth::user()->timezone);

        $request->session()->flash('success', trans('people.significant_other_edit_success'));

        return redirect('/people/'.$contact->id);
    }

    /**
     * Removes the significant other.
     * @param  Request $request
     * @param  int  $peopleId
     * @return string
     */
    public function deleteSignificantOther(Request $request, $contactId, $significantOtherId)
    {
        $contact = Contact::findOrFail($contactId);
        $significantOther = SignificantOther::findOrFail($significantOtherId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        if ($significantOther->contact_id != $contact->id) {
            return redirect()->route('people.index');
        }

        $contact->deleteSignificantOther($significantOtherId);
        $request->session()->flash('success', trans('people.significant_other_delete_success'));

        return redirect()->route('people.show', ['id' => $contact->id]);
    }

    /**
     * Show the Gifts page.
     * @param  int $peopleId
     * @return
     */
    public function gifts($contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $data = [
            'contact' => $contact,
        ];

        return view('people.gifts.index', $data);
    }

    /**
     * Show the Add gift screen.
     * @param int $id ID of the people object
     */
    public function addGift($contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $data = [
            'contact' => $contact,
        ];

        return view('people.gifts.add', $data);
    }

    /**
     * Actually store the gift.
     * @param  Request $request
     * @param  int  $peopleId
     * @return
     */
    public function storeGift(Request $request, $contactId)
    {
        $contact = Contact::findOrFail($contactId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        $title = $request->input('title');
        $url = $request->input('url');
        $value = $request->input('value');
        $comment = $request->input('comment');
        $giftOffered = $request->input('gift-offered');

        $gift = new Gift;
        $gift->contact_id = $contact->id;
        $gift->account_id = $contact->account_id;
        $gift->name = encrypt($title);

        if ($url != '') {
            $gift->url = encrypt($url);
        }

        if ($value != '') {
            $gift->value_in_dollars = $value;
        }

        if ($comment != '') {
            $gift->comment = encrypt($comment);
        }

        if ($giftOffered == 'is_an_idea') {
            $gift->is_an_idea = 'true';
            $gift->has_been_offered = 'false';
        } else {
            $gift->is_an_idea = 'false';
            $gift->has_been_offered = 'true';
        }

        // is this gift for someone in particular?
        $giftForSomeone = $request->input('giftForSomeone');
        if ($giftForSomeone != null) {
            $lovedOne = $request->input('lovedOne');
            $type = substr($lovedOne, 0, 1);

            if ($type == 'K') {
                $objectType = 'kid';
                $objectId = substr($lovedOne, 1);
            } else {
                $objectType = 'significantOther';
                $objectId = substr($lovedOne, 1);
            }

            $gift->about_object_id = $objectId;
            $gift->about_object_type = $objectType;
        }

        $gift->save();

        $request->session()->flash('success', trans('people.gifts_add_success'));

        return redirect('/people/'.$contact->id);
    }

    /**
     * Mark the gift as deleted.
     * @param  Request $request
     * @param  int  $peopleId
     * @param  int $reminderId
     */
    public function deleteGift(Request $request, $contactId, $giftId)
    {
        $contact = Contact::findOrFail($contactId);
        $gift = Gift::findOrFail($giftId);

        if ($contact->account_id != Auth::user()->account_id) {
            return redirect()->route('people.index');
        }

        if ($gift->contact_id != $contact->id) {
            return redirect()->route('people.index');
        }

        $gift->delete();

        $request->session()->flash('success', trans('people.gifts_delete_success'));

        return redirect('/people/'.$contact->id);
    }
}
