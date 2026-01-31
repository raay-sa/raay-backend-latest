// import '../app';
// import { WebRTCAdaptor } from "@antmedia/webrtc_adaptor";

// let webRTCAdaptor;
// let streamId = null;
// let token = null;
// const programId = 1;

// const startVideoBtn = document.getElementById("open_meeting");
// const stopVideoBtn = document.getElementById("close_meeting");
// const statusText = document.getElementById("status");

// startVideoBtn.onclick = () => {
//     fetch(`/create-stream`, {
//         method: "POST",
//         headers: {
//             "Content-Type": "application/json",
//             "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content
//         },
//         body: JSON.stringify({ program_id: programId })
//     })
//     .then(res => res.json())
//     .then(data => {
//         console.log("Server response:", data);
//         streamId = data.streamId || data.id;

//         token = null; // لو فيه حماية حط التوكن هنا
//         initPublisher(); // ده هيعمل publish حتى لو كان Offline
//     });
// };


// // remove stream
// stopVideoBtn.onclick = () => {
//     if (!streamId) return;

//     // وقف البث من Ant Media
//     if (webRTCAdaptor) {
//         webRTCAdaptor.stop(streamId);
//         stopLocalTracks();

//         // قفل الويب سوكيت مره واحدة فقط
//         if (webRTCAdaptor.webSocket?.readyState === WebSocket.OPEN) {
//             webRTCAdaptor.closeWebSocket();
//         }
//     }

//     // API لحذف الستريم
//     fetch(`/delete-stream/${streamId}`, {
//         method: "DELETE",
//         headers: {
//             "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content,
//             "Accept": "application/json"
//         },
//         body: JSON.stringify({ program_id: programId })
//     })

//     .then(res => res.json())
//     .then(data => {
//         console.log("Stream deleted:", data);
//     });

//     statusText.textContent = "🛑 Video stopped";
//     startVideoBtn.disabled = false;
//     stopVideoBtn.disabled = true;
// };



// // function stopLocalTracks() {
// //     const streams = [webRTCAdaptor.localStream, webRTCAdaptor.mediaManager?.localStream];
// //     streams.forEach(s => s?.getTracks().forEach(t => t.stop()));
// // }
// function stopLocalTracks() {
//     if (webRTCAdaptor?.localStream) {
//         webRTCAdaptor.localStream.getTracks().forEach(track => {
//             track.stop();
//         });
//         webRTCAdaptor.localStream = null;
//     }

//     if (webRTCAdaptor?.mediaManager?.localStream) {
//         webRTCAdaptor.mediaManager.localStream.getTracks().forEach(track => {
//             track.stop();
//         });
//         webRTCAdaptor.mediaManager.localStream = null;
//     }
// }


// function initPublisher() {
//     webRTCAdaptor = new WebRTCAdaptor({
//         websocket_url: "wss://media.esol.sa:5443/Raay/websocket",
//         mediaConstraints: { video: true, audio: true }, // 🎯 فيديو وصوت
//         localVideoId: "localVideo", // هنا هيظهر الفيديو المحلي
//         isPlayMode: false,
//         debug: true,
//         callback(info) {
//             if (info === "initialized") {
//                 webRTCAdaptor.publish(streamId, token);
//                 statusText.textContent = "📡 Publishing video...";
//             }
//             if (info === "publish_started") {
//                 statusText.textContent = "✅ LIVE VIDEO!";
//                 startVideoBtn.disabled = true;
//                 stopVideoBtn.disabled = false;
//             }
//             if (info === "publish_finished") {
//                 statusText.textContent = "🛑 Video stopped";
//             }
//         },
//         callbackError(error) {
//             statusText.textContent = "❌ " + error;
//         }
//     });
// }




// window.Echo.channel('viewer-count')
//     .listen('.viewer-count-event', (data) => {
//         document.getElementById("viewerCount").innerText = data.count;
//     });



import '../app';
import { WebRTCAdaptor } from "@antmedia/webrtc_adaptor";

let webRTCAdaptor;
let streamId = null;
let token = null;
const programId = 1;

const startVideoBtn = document.getElementById("open_meeting");
const stopVideoBtn = document.getElementById("close_meeting");
const statusText = document.getElementById("status");

const sendBtn = document.getElementById("sendBtn");
const messageInput = document.getElementById("message_input");
const chatBox = document.getElementById("chat-box");

// زرار بدء البث
startVideoBtn.onclick = () => {
    fetch(`/create-stream`, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content,
            "Accept": "application/json" // Explicitly ask for JSON
        },
        body: JSON.stringify({ program_id: programId })
    })
    .then(async res => {
        if (!res.ok) {
            const error = await res.text();
            throw new Error(error);
        }
        return res.json();
    })
    .then(data => {
        if (data.error) {
            throw new Error(data.error);
        }
        console.log("Server response:", data);
        streamId = data.streamId || data.id;
        token = null;
        initPublisher();
    })
    .catch(error => {
        console.error("Stream creation failed:", error);
        statusText.textContent = "❌ Error: " + error.message;
    });
};

// زرار إيقاف البث
stopVideoBtn.onclick = () => {
    if (!streamId) return;

    if (webRTCAdaptor) {
        webRTCAdaptor.stop(streamId);
        stopLocalTracks();

        if (webRTCAdaptor.webSocket?.readyState === WebSocket.OPEN) {
            webRTCAdaptor.closeWebSocket();
        }
    }

    fetch(`/delete-stream/${streamId}`, {
        method: "DELETE",
        headers: {
            "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content,
            "Accept": "application/json"
        }
    })
    .then(res => res.json())
    .then(data => console.log("Stream deleted:", data));

    statusText.textContent = "🛑 Video stopped";
    startVideoBtn.disabled = false;
    stopVideoBtn.disabled = true;
};

// وقف الكاميرا والمايك
function stopLocalTracks() {
    if (webRTCAdaptor?.localStream) {
        webRTCAdaptor.localStream.getTracks().forEach(track => track.stop());
        webRTCAdaptor.localStream = null;
    }
    if (webRTCAdaptor?.mediaManager?.localStream) {
        webRTCAdaptor.mediaManager.localStream.getTracks().forEach(track => track.stop());
        webRTCAdaptor.mediaManager.localStream = null;
    }
}

// تهيئة الـ Publisher
function initPublisher() {
    webRTCAdaptor = new WebRTCAdaptor({
        websocket_url: "wss://media.esol.sa:5443/Raay/websocket",
        mediaConstraints: { video: true, audio: true },
        localVideoId: "localVideo",
        isPlayMode: false,
        debug: true,
        dataChannelEnabled: true,
        callback(info, obj) {
            if (info === "initialized") {
                webRTCAdaptor.publish(streamId, token);
                statusText.textContent = "📡 Publishing video...";
            }
            // else if (info === "data_received") {
            //     if (obj?.event?.data) {
            //         console.log("📩 رسالة من طالب:", obj.event.data);
            //         displayChatMessage("👤 Student: " + obj.event.data);
            //     } else {
            //         console.log("ℹ️ Event داخلي من السيرفر:", obj.data);
            //     }
            // }
            else if (info === "data_received") {
                // نتاكد من وجود البيانات
                const data = obj?.event?.data || obj?.data;
                if (data) {
                    console.log("📩 رسالة DataChannel وصلت:", data);
                    // عرضها في صندوق الشات
                    displayChatMessage("👤 Student: " + data);
                }
            }
            else if (info === "publish_started") {
                statusText.textContent = "✅ LIVE VIDEO!";
                startVideoBtn.disabled = true;
                stopVideoBtn.disabled = false;
            }
            else if (info === "publish_finished") {
                statusText.textContent = "🛑 Video stopped";
            }
        },
        callbackError(error) {
            statusText.textContent = "❌ " + error;
        }
    });
}

// إرسال رسالة
function sendChatMessage(message) {
    if (!message.trim() || !streamId) return;

    // ✅ المدرس والطالب هيبعتوا لنفس الـ streamId
    webRTCAdaptor.sendData(streamId, message);

    // ✅ الرسالة تظهر فوراً عند المرسل
    displayChatMessage("🙋‍♂️ You: " + message);

    messageInput.value = "";
}


// عرض الرسائل في الصندوق
function displayChatMessage(message) {
    const div = document.createElement("div");
    div.textContent = message;
    chatBox.appendChild(div);
    chatBox.scrollTop = chatBox.scrollHeight; // ينزل لآخر رسالة
}

// عند الضغط على زر الإرسال
sendBtn.onclick = () => {
    sendChatMessage(messageInput.value);
};

// أو بالـ Enter
messageInput.addEventListener("keypress", function (e) {
    if (e.key === "Enter") {
        sendChatMessage(messageInput.value);
    }
});
