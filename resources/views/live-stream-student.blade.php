@vite('resources/js/voice/student.js')
{{-- @vite('resources/js/chat/chat.js') --}}

<meta name="csrf-token" content="{{ csrf_token() }}">

<h1>Ant Media Video Test</h1>

<br>
<br>

<video id="localVideo" autoplay playsinline style="width: 500px; border: 2px solid #ccc; border-radius: 10px;"></video>

<br>
<br>

<div id="status" class="inactive">Video is inactive</div>
<div id="error" class="error" style="display: none;"></div>

{{-- close meeting --}}
{{-- chat --}}



<br>
<br>

<div id="chat-box" style="border:1px solid #ccc; height:100px; overflow-y:auto; padding:10px;">
    <!-- هنا هتظهر الرسائل -->
</div>

<input type="text" id="message_input" placeholder="اكتب رسالة..." required>
<button id="sendBtn">Send</button>

{{--
<form id="message-form" method="POST">
    @csrf
    message: <input type="text" id="message_input" required>
    <button type="submit">Send</button>
</form>

<div id="messages-box"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
    document.getElementById('message-form').addEventListener('submit', function (e) {
        e.preventDefault();
        let message = document.getElementById('message_input').value;
        sendMessage(message);
    });

    function sendMessage(message) {
        $.ajax({
            url: "{{ route('send-msg') }}",
            type: "POST",
            headers: {
                "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content")
            },
            data: {
                content: message,
                program_id: 1,
                session_id: 1,
            },
            success: function(response) {
              document.getElementById('message_input').value = '';
              console.log(response)
            },
            error: function(xhr) {
                console.error(xhr.responseText);
            }
        });
    }
</script>

 --}}
