<h2>🚨 Absence Alert</h2>

<p>
Employee <strong>{{ $absentee['user_name'] }}</strong>
did not check in for shift <strong>{{ $absentee['shift'] }}</strong>.
</p>

@if(isset($absentee['suggested_replacement']))
<p>
Suggested replacement: <strong>{{ $absentee['suggested_replacement']['name'] ?? 'N/A' }}</strong>
</p>
@endif