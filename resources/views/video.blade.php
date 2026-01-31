{{-- <h4>upload video</h4>

<div x-data="uploadForm()" class="p-6">
    <input type="file" @change="uploadFile($event)" />

    <div class="mt-4 w-full bg-gray-200 rounded-full h-4">
        <div class="bg-blue-600 h-4 rounded-full" :style="`width: ${progress}%;`"></div>
    </div>

    <p x-text="progress + '%'"></p>
</div>

<script src="https://unpkg.com/alpinejs" defer></script>
<script>
    function uploadForm() {
        return {
            progress: 0,
            uploadFile(event) {
                let file = event.target.files[0];
                let formData = new FormData();
                formData.append('file', file);

                let xhr = new XMLHttpRequest();
                xhr.open('POST', '{{ route('upload') }}', true);
                xhr.setRequestHeader('X-CSRF-TOKEN', '{{ csrf_token() }}');

                xhr.upload.addEventListener("progress", (e) => {
                    if (e.lengthComputable) {
                        this.progress = Math.round((e.loaded / e.total) * 100);
                    }
                });

                xhr.onload = () => {
                    if (xhr.status === 200) {
                        console.log('Uploaded:', JSON.parse(xhr.responseText));
                    }
                };

                xhr.send(formData);
            }
        }
    }
</script>
 --}}








 <!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Video Upload</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<body class="bg-gray-100 min-h-screen py-8">

<h4 class="text-2xl font-bold text-center mb-8">Upload Video</h4>
<div x-data="uploadForm()" class="p-6 max-w-md mx-auto bg-white rounded-lg shadow-md">
    <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-2">
            Select Video File
        </label>
        <input
            type="file"
            @change="uploadFile($event)"
            accept="video/mp4,video/avi,video/mov,video/wmv,video/flv,video/webm"
            :disabled="isUploading"
            class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 disabled:opacity-50"
        />
        <p class="text-xs text-gray-500 mt-1">
            Supported formats: MP4, AVI, MOV, WMV, FLV, WebM (max 200MB)
        </p>
    </div>

    <!-- Debug Info -->
    <div class="mb-4 p-2 bg-gray-50 text-xs text-gray-600 rounded" x-data="{ show: false }">
        <button @click="show = !show" class="text-blue-600 underline">Show Debug Info</button>
        <div x-show="show" class="mt-2">
            <p><strong>CSRF Token:</strong> <span x-text="document.querySelector('meta[name=\"csrf-token\"]')?.content?.substring(0, 10) + '...'"></span></p>
            <p><strong>Upload URL:</strong> /upload</p>
        </div>
    </div>

    <!-- شريط التقدم -->
    <div x-show="isUploading" class="mb-4" x-transition>
        <div class="flex justify-between mb-1">
            <span class="text-base font-medium text-blue-700">Uploading...</span>
            <span class="text-sm font-medium text-blue-700" x-text="progress + '%'"></span>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-3">
            <div class="bg-blue-600 h-3 rounded-full transition-all duration-300 ease-in-out"
                 :style="`width: ${progress}%;`"></div>
        </div>
    </div>

    <!-- رسالة النجاح مع تفاصيل الفيديو -->
    <div x-show="success && uploadedVideo" class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg" x-transition>
        <div class="flex items-start">
            <svg class="flex-shrink-0 w-5 h-5 text-green-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
            </svg>
            <div class="ml-3">
                <h4 class="text-sm font-medium text-green-800">Upload Successful!</h4>
                <div class="mt-2 text-sm text-green-700" x-show="uploadedVideo">
                    <p><strong>Duration:</strong> <span x-text="uploadedVideo?.duration_formatted"></span></p>
                    <p><strong>Size:</strong> <span x-text="uploadedVideo ? (uploadedVideo.file_size / 1024 / 1024).toFixed(2) + ' MB' : ''"></span></p>
                    <p x-show="uploadedVideo?.width"><strong>Resolution:</strong> <span x-text="uploadedVideo?.width + 'x' + uploadedVideo?.height"></span></p>
                </div>
            </div>
        </div>
    </div>

    <!-- رسالة الخطأ -->
    <div x-show="error" class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg" x-transition>
        <div class="flex items-start">
            <svg class="flex-shrink-0 w-5 h-5 text-red-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
            </svg>
            <div class="ml-3">
                <h4 class="text-sm font-medium text-red-800">Upload Failed</h4>
                <p class="text-sm text-red-700 mt-1" x-text="error"></p>
            </div>
        </div>
    </div>

    <!-- زر إعادة المحاولة -->
    <button
        x-show="error && !isUploading"
        @click="resetState()"
        class="w-full mt-2 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors"
    >
        Try Again
    </button>

    <!-- زر إعادة تحميل للـ CSRF -->
    <button
        x-show="error && error.includes('token')"
        @click="window.location.reload()"
        class="w-full mt-2 px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-colors"
    >
        Refresh Page
    </button>
</div>

<script>
    function uploadForm() {
        return {
            progress: 0,
            isUploading: false,
            error: null,
            success: null,
            uploadedVideo: null,

            uploadFile(event) {
                let file = event.target.files[0];

                if (!file) {
                    this.error = 'Please select a file';
                    return;
                }

                console.log('File selected:', {
                    name: file.name,
                    size: file.size,
                    type: file.type
                });

                // تحقق من CSRF token قبل البدء
                const csrfToken = document.querySelector('meta[name="csrf-token"]');
                if (!csrfToken || !csrfToken.getAttribute('content')) {
                    this.error = 'Security token missing. Please refresh the page.';
                    return;
                }

                // التحقق من نوع الملف
                const allowedTypes = [
                    'video/mp4', 'video/avi', 'video/mov', 'video/wmv',
                    'video/flv', 'video/webm', 'video/quicktime'
                ];

                if (!allowedTypes.includes(file.type)) {
                    this.error = 'Please select a valid video file (MP4, AVI, MOV, WMV, FLV, WebM)';
                    return;
                }

                // التحقق من حجم الملف (200MB max)
                const maxSize = 200 * 1024 * 1024;
                if (file.size > maxSize) {
                    this.error = 'File size must be less than 200MB';
                    return;
                }

                // التحقق من الحد الأدنى لحجم الملف
                if (file.size < 1024) {
                    this.error = 'File seems to be corrupted or too small';
                    return;
                }

                this.resetState();
                this.isUploading = true;

                let formData = new FormData();
                formData.append('file', file);

                let xhr = new XMLHttpRequest();
                xhr.open('POST', '/upload', true);

                // إضافة Headers بما في ذلك CSRF Token
                xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken.getAttribute('content'));
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                console.log('CSRF Token:', csrfToken.getAttribute('content').substring(0, 20) + '...');

                xhr.upload.addEventListener("progress", (e) => {
                    if (e.lengthComputable) {
                        this.progress = Math.round((e.loaded / e.total) * 100);
                    }
                });

                xhr.onload = () => {
                    this.isUploading = false;

                    console.log('Response status:', xhr.status);
                    console.log('Response headers:', xhr.getAllResponseHeaders());
                    console.log('Response text:', xhr.responseText);

                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                this.success = 'Video uploaded successfully!';
                                this.uploadedVideo = response;
                                console.log('Upload successful:', response);
                                this.displayVideoInfo(response);
                            } else {
                                this.error = response.error || 'Upload failed';
                                console.error('Upload failed:', response);
                            }
                        } catch (e) {
                            this.error = 'Invalid server response';
                            console.error('JSON Parse Error:', e);
                        }
                    } else if (xhr.status === 419) {
                        this.error = 'Security token expired. Please refresh the page and try again.';
                        console.error('CSRF Token Mismatch - Status 419');
                    } else if (xhr.status === 422) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.errors) {
                                const firstError = Object.values(response.errors)[0];
                                this.error = Array.isArray(firstError) ? firstError[0] : firstError;
                            } else {
                                this.error = response.error || 'Validation failed';
                            }
                        } catch (e) {
                            this.error = 'Validation failed';
                        }
                    } else {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            this.error = response.error || `Server error (${xhr.status})`;
                        } catch (e) {
                            this.error = `Server error occurred (${xhr.status})`;
                        }
                    }

                    if (this.error || this.success) {
                        setTimeout(() => {
                            this.progress = 0;
                        }, 2000);
                    }
                };

                xhr.onerror = () => {
                    this.isUploading = false;
                    this.error = 'Network error occurred. Please check your connection.';
                    console.error('Network error');
                };

                xhr.ontimeout = () => {
                    this.isUploading = false;
                    this.error = 'Upload timeout. Please try again.';
                    console.error('Upload timeout');
                };

                xhr.timeout = 300000; // 5 minutes

                xhr.send(formData);
            },

            resetState() {
                this.error = null;
                this.success = null;
                this.progress = 0;
                this.uploadedVideo = null;
            },

            displayVideoInfo(video) {
                console.log(`Video Details:
    - Duration: ${video.duration_formatted} (${video.duration_seconds}s)
    - Size: ${(video.file_size / 1024 / 1024).toFixed(2)} MB
    - Resolution: ${video.width}x${video.height}
    - Bitrate: ${video.bitrate ? Math.round(video.bitrate / 1000) + ' kbps' : 'Unknown'}
    - Path: ${video.path}`);
            }
        }
    }
</script>

</body>
</html>
