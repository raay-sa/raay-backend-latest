@vite('resources/js/voice/teacher.js')

<meta name="csrf-token" content="{{ csrf_token() }}">

<h1>Ant Media Video Test</h1>

<button id="open_meeting">🎙 start Live course</button>
<button id="close_meeting" disabled>🛑 Finish Live course</button>

<br>
<br>

<button id="open_camera">🎙 Open camera</button>
<button id="close_camera" disabled>🛑 close camera</button>

<br>
<br>

<button id="open_mic">🎙 open mic</button>
<button id="close_mic" disabled>🛑 close mic</button>

<br>
<br>

<button id="open_sharing">🎙 open share scree</button>
<button id="close_sharing" disabled>🛑 close share scree</button>

<br>
<br>

{{-- <audio id="localAudio" autoplay controls></audio> --}}
<video id="localVideo" autoplay playsinline muted style="width: 500px; border: 2px solid #ccc; border-radius: 10px;"></video>

<br>
<br>

<div id="status" class="inactive">Video is inactive</div>
<div id="error" class="error" style="display: none;"></div>

{{-- <div id="count">count of viewers: <span id="viewerCount">0</span></div> --}}
{{-- students where open this meeting and sign of mic --}}
{{-- chat --}}


<br>
<br>

<div id="chat-box" style="border:1px solid #ccc; height:100px; overflow-y:auto; padding:10px;">
    <!-- هنا هتظهر الرسائل -->
</div>

<input type="text" id="message_input" placeholder="اكتب رسالة..." required>
<button id="sendBtn">Send</button>
