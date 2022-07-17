<?php

namespace App\Http\Controllers\Frontend;

use DateTime;
use Carbon\Carbon;
use App\Models\Stations;
use App\Models\Reservation;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;
use Intervention\Image\Facades\Image;

class ReservationController extends Controller
{

    public function index()
    {
        $userLoggedin = auth()->user();
        // dd($userLoggedin);
        $reservation = Reservation::where('user_id', $userLoggedin['id'])->get();
        return view('backend.reservation.user.index', compact('reservation', 'userLoggedin'));
    }

    public function edit(Reservation $reservation)
    {
        // $dateOriginal = $reservation->start_date;
        // dd($dateOriginal);
        $stations = Stations::pluck('stationName', 'id');
        $station = Stations::find($reservation->station_id);
        return view('backend.reservation.user.edit', compact('reservation', 'stations', 'station'));
    }

    public function show(Reservation $reservation)
    {
       return view('backend.reservation.user.show', compact('reservation'));
    }

    public function update(Request $request, Reservation $reservation)
    {
        $dateOriginal = (new DateTime($reservation->start_date))->format('Y-m-d');
        // dd($dateOriginal);

        $userLoggedin = auth()->user();

        $data = request()->validate([
            'station_id' => 'numeric|required',
            'start_date' => 'required|date_format:Y-m-d H:i:s',
            'end_date' => 'required|date_format:Y-m-d H:i:s',
            'E_numbers' => 'string|required', // TODO: Validate E-Numbers
            'thumb' => 'image|nullable|mimes:jpeg,jpg,png,jpg,gif,svg|max:4096', // TODO: Maybe we need to increase the file size
            'thumb_after' => 'image|nullable|mimes:jpeg,jpg,png,jpg,gif,svg|max:4096' // TODO: Maybe we need to increase the file size
        ]);

        if ($request->thumb != null) {
            $data['thumb'] = $this->uploadThumb($reservation->thumbURL(), $request->thumb, "reservations");
        }

        if ($request->thumb_after != null) {
            $data['thumb_after'] = $this->uploadThumb($reservation->thumbURL_after(), $request->thumb_after, "reservations_after");
        }

        // $reservation->update($data);
        // return redirect()->route('frontend.reservation.index')->with('Success', 'Reservation was updated !');

        $dateNew = (new DateTime($data['start_date']))->format('Y-m-d');

        $date1 = Carbon::createFromFormat('Y-m-d', $dateOriginal);
        $date2 = Carbon::createFromFormat('Y-m-d', $dateNew);
        $result = $date1->eq($date2);

        // dd($result);

        $start = new DateTime($request['start_date']);
        $end = new DateTime($request['end_date']);
        $diff = $start->diff($end);
        $data['duration'] = ($diff->h * 60) + ($diff->s / 60) + ($diff->i) + ($diff->d * 24 * 60) + ($diff->m * 30 * 24 * 60) + ($diff->y * 365 * 24 * 60);

        //For overlap check
        $res = Reservation::whereDate('start_date', $start)->where('station_id', $data['station_id'])->get();

        $flag = false;
        foreach ($res as $r) {
            if ($r->user_id != $userLoggedin['id']) {
                $flag = $this->isAnOverlapEvent($start, $end, $r);
            }
        }
       
        // See if the user has already made a reservation on that day for this station
        $bookings1 = Reservation::whereDate('start_date', $start)->where('user_id', $userLoggedin['id'])->where('station_id', $data['station_id'])->get();

        // See whether the reservation is bein made too early

        $todayDate = date('Y-m-d');
        $today = new DateTime($todayDate);

        $resDiff = $today->diff($start);
        $minutesDiff = ($resDiff->h*60) + ($resDiff->s/60) + ($resDiff->i) + ($resDiff->d*24*60) + ($resDiff->m*30*24*60) + ($resDiff->y*365*24*60);
    

        $data = [
            'station_id' => $request['station_id'],
            'start_date' => $request['start_date'],
            'end_date' => $request['end_date'],
            'E_numbers' => $request['E_numbers'],
            'duration' => $minutes,
            'thumb' => $request->thumb,
            'thumb_after' => $request->thumb_after,
            
        ];

        if ($userLoggedin['id'] != $reservation->user_id){
            return redirect()->route('frontend.reservation.index')->with('Error', 'You can not update this reservation');   
        }elseif(($minutesDiff > 43200)){
            return redirect()->route('frontend.reservation.index')->with('Error', 'You can not make a reservation for that date this early');   
        }elseif($data['duration'] > 240){
            return redirect()->route('frontend.reservation.index')->with('Error', 'Reservation was not updated! Reservation can not exceed 4 hours');           
        }elseif($flag){
            return redirect()->route('frontend.reservation.index')->with('Error', 'Reservation was not updated! Time slot not available');   
        }elseif((count($bookings1) == 1) && $result && !$flag && ($minutesDiff < 43200)){
            $reservation->update($data);
            return redirect()->route('frontend.reservation.index')->with('Success', 'Reservation was updated !');            
        }elseif((count($bookings1) == 0 && !$flag && ($minutesDiff < 43200))){
            $reservation->update($data);
            return redirect()->route('frontend.reservation.index')->with('Success', 'Reservation was updated !');
        } elseif ((count($bookings1) == 1) && !$result) {
            return redirect()->route('frontend.reservation.index')->with('Error', 'Reservation was not updated! Can not make multiple reservations in one day');
        }
    }

    public function isAnOverlapEvent(DateTime $eventStartDay, DateTime $eventEndDay, Reservation $res)
    {
        // var events = $('#calendar').fullCalendar('clientEvents');
        $resStart = new DateTime($res->start_date);
        $resEnd = new DateTime($res->end_date);

        // dd($eventStartDay, $res->start_date);
        // start-time in between any of the events
        if ($eventStartDay > $resStart && $eventStartDay < $resEnd) {
            // dd('hi1');
            return true;
        }
        //end-time in between any of the events
        if ($eventEndDay > $resStart && $eventEndDay < $resEnd) {
            // dd('hi2');
            return true;
        }
        //any of the events in between/on the start-time and end-time
        if ($eventStartDay <= $resStart && $eventEndDay >= $resEnd) {
            // dd('hi3');
            return true;
        }

        // dd('hi4');
        return false;
    }

    /**
     * Confirm to delete the specified resource from storage.
     *
     * @param \App\Models\EquipmentItem $equipmentItem
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function delete(Reservation $reservation)
    {
        $station = Stations::find($reservation->station_id);
        return view('backend.reservation.user.delete', compact('reservation', 'station'));
    }

    public function destroy(Reservation $reservation)
    {
        $userLoggedIn = auth()->user();
        $booking = Reservation::find($reservation->id);

        $this->deleteThumb($reservation->thumbURL());
        $this->deleteThumb($reservation->thumbURL_after());

        if (!$booking) {
            return response()->json([
                'error' => 'Unable to locate the event'
            ], 404);
        }

        if ($userLoggedIn['id'] == $booking->user_id) {
            $booking->delete();
            return redirect()->route('frontend.reservation.index')->with('Success', 'Reservation was deleted !');
        } else {
            return redirect()->route('frontend.reservation.index')->with('Error', 'You can not delete this reservation!');
        }

    }

    private function deleteThumb($currentURL)
    {
        if ($currentURL != null) {
            $oldImage = public_path($currentURL);
            if (File::exists($oldImage)) unlink($oldImage);
        }
    }

    // Private function to handle thumb images
    private function uploadThumb($currentURL, $newImage, $folder)
    {
        // Delete the existing image
        $this->deleteThumb($currentURL);

        $imageName = time() . '.' . $newImage->extension();
        $newImage->move(public_path('img/' . $folder), $imageName);
        $imagePath = "/img/$folder/" . $imageName;

        // TODO: Maybe we should not crop the images in here
        $image = Image::make(public_path($imagePath))->fit(360, 360);
        $image->save();

        return $imageName;
    }


}
