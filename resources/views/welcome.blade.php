welcome page

<!DOCTYPE html>
<head>
    <title>Pusher Test</title>
    <script src="https://js.pusher.com/8.4.0/pusher.min.js"></script>
    <script>

            // Enable pusher logging - don't include this in production
            Pusher.logToConsole = true;
            var pusher = new Pusher('a349c9bb6a9749d1839b', {
            cluster: 'eu'
            });

            var channel = pusher.subscribe('test-channel');
            channel.bind('TestEvent', function(data) {
                alert(JSON.stringify(data));
            });
    </script>


</head>
<body>
    <h5>pusher</h5>
    {{-- <form action="{{ route('send-msg') }}" method="POST">
        @csrf
        <input type="text" name="content">
        <button type="submit">Send</button>
    </form> --}}

<form id="msgForm">
    @csrf
    <input type="text" name="content" id="msgInput">
    <button type="submit">Send</button>
</form>



<script>
    document.getElementById('msgForm').addEventListener('submit', function(e) {
        e.preventDefault();

        fetch("{{ route('send-msg') }}", {
            method: "POST",
            headers: {
                "X-CSRF-TOKEN": document.querySelector('input[name="_token"]').value,
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                content: document.getElementById('msgInput').value
            })
        })
        .then(res => res.json())
        // .then(data => console.log("Message sent:", data));
    });
</script>


</body>
