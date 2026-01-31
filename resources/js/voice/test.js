import '../app';

console.log('test');

// Test connection status
window.Echo.connector.pusher.connection.bind('connected', function() {
    console.log('Successfully connected to Reverb!');
});

window.Echo.connector.pusher.connection.bind('error', function(err) {
    console.log('Connection error:', err);
});

window.Echo.channel('test-channel')
    .listen('.TestEvent', (e) => {
        // alert('dff');
        console.log('Message from server:', e.message);
    });
