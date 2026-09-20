<?php

namespace App\Support;

enum TrackingMode: string
{
    case RemoteTimer = 'remote_timer';
    case OfficeAttendance = 'office_attendance';
    case None = 'none';
}
