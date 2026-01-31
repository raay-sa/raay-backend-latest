import '../app';
import { WebRTCAdaptor } from "@antmedia/webrtc_adaptor";

let webRTCAdaptor;
let currentStreamId = null;   // ✅ تعريف المتغيّر
const programId = 1; // البرنامج اللي هيسمعه المستخدم

const sendBtn = document.getElementById("sendBtn");
const messageInput = document.getElementById("message_input");
const chatBox = document.getElementById("chat-box");

// بمجرد تحميل الصفحة أو عند الحاجة
fetch(`/get-stream/program/${programId}`)
    .then(res => {
        if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
        return res.json();
    })
    .then(data => {
        console.log("Server response:", data);
        const streamId = data.streamId;
        if (streamId) {
            currentStreamId = streamId; // ✅ خزّن streamId
            initPlayer(streamId);
        } else {
            console.error("No streamId found for this program.");
        }
    })
    .catch(err => console.error("Fetch error:", err));


function initPlayer(streamId) {
    webRTCAdaptor = new WebRTCAdaptor({
        websocket_url: "wss://media.esol.sa:5443/Raay/websocket",
        mediaConstraints: { audio: true, video: true },
        remoteVideoId: "localVideo",
        isPlayMode: true,
        debug: true,
        callback(info, obj) {
            if (info === "initialized") {
                webRTCAdaptor.play(streamId, null);
            }
           else if (info === "data_received") {
                let data = obj?.event?.data || obj?.data;

                // بعض الرسائل النصية ممكن تكون JSON حقيقي، فلذلك نحاول parse لكن لا نرجع إلا النص
                try {
                    const parsed = JSON.parse(data);

                    // لو فيه eventType أو audioLevel يبقى رسالة نظام => تجاهل
                    if (parsed.eventType || parsed.audioLevel) {
                        return;
                    }

                    // لو JSON لكن بدون eventType/ audioLevel اعتبره رسالة مدرس
                    data = typeof parsed === "string" ? parsed : data;
                } catch (e) {
                    // مش JSON اعتبره رسالة نصية مباشرة
                }

                console.log("📩 رسالة من المدرس:", data);
                displayChatMessage("👤 Teacher: " + data);
            }

            else if (info === "play_started") {
                console.log("✅ Playback started");
            }
            else if (info === "play_finished") {
                console.log("🛑 Playback finished");
            }
        },
        callbackError(error) {
            if (error === "no_stream_exist") {
                console.warn("Stream not live yet, retrying...");
                console.log(streamId)
                // setTimeout(() => webRTCAdaptor.play(streamId, null), 3000);
            } else {
                console.error("WebRTC Error:", error);
            }
        }
    });
}


// 3) إرسال رسالة من المشاهد
function sendChatMessage(message) {
    if (!message.trim() || !currentStreamId) return;

    // الطالب يبعت على نفس currentStreamId اللي بيلعب منه
    webRTCAdaptor.sendData(currentStreamId, message);

    // يظهر عنده في الشات
    displayChatMessage("🙋‍♂️ You: " + message);

    messageInput.value = "";
}


// 4) عرض الرسائل في صندوق الشات
function displayChatMessage(message) {
    const div = document.createElement("div");
    div.textContent = message;
    chatBox.appendChild(div);
    chatBox.scrollTop = chatBox.scrollHeight;
}

// 5) ربط الأزرار
sendBtn.onclick = () => sendChatMessage(messageInput.value);

messageInput.addEventListener("keypress", function (e) {
    if (e.key === "Enter") {
        sendChatMessage(messageInput.value);
    }
});






// import '../app';
// import { WebRTCAdaptor } from "@antmedia/webrtc_adaptor";

// let webRTCAdaptor;
// const programId = 1; // البرنامج اللي هيسمعه المستخدم

// // بمجرد تحميل الصفحة أو عند الحاجة
// fetch(`/get-stream/program/${programId}`)
//     .then(res => {
//         if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
//         return res.json();
//     })
//     .then(data => {
//         console.log("Server response:", data);
//         const streamId = data.streamId;
//         if (streamId) {
//             initPlayer(streamId);
//         } else {
//             console.error("No streamId found for this program.");
//         }
//     })
//     .catch(err => console.error("Fetch error:", err));



// function initPlayer(streamId) {
//     webRTCAdaptor = new WebRTCAdaptor({
//         websocket_url: "wss://media.esol.sa:5443/Raay/websocket",
//         mediaConstraints: { audio: true, video: true },
//         remoteVideoId: "localVideo",
//         isPlayMode: true,
//         debug: true,
//         callback(info) {
//             if (info === "initialized") {
//                 webRTCAdaptor.play(streamId, null); // null لو مفيش JWT
//             }
//         },
//         callbackError(error) {
//             if (error === "no_stream_exist") {
//                 console.warn("Stream not live yet, retrying...");
//                 console.log(streamId)
//                 // setTimeout(() => webRTCAdaptor.play(streamId, null), 3000);
//             } else {
//                 console.error("WebRTC Error:", error);
//             }
//         }
//     });
// }






// // window.Echo.channel('program-channel')
// //     .listen('.program-event', (data) => {
// //         if (data.status === 'live') {
// //             console.log("Stream started:", data.stream);
// //             initPlayer(data.stream);
// //         } else if (data.status === 'closed') {
// //             console.log("Stream closed");
// //             if (webRTCAdaptor) {
// //                 webRTCAdaptor.stop();
// //             }
// //             document.getElementById("localVideo").srcObject = null;
// //         }
// //     });

