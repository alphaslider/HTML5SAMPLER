<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GK_ULTRA // Multi-Grade Sampler</title>
    <style>
        /* CSS uses data.ttf for small fonts and buttons as requested */
        @font-face {
            font-family: 'data';
            src: url('data.ttf'); /* Ensure this is in the same directory */
        }

        body { 
            font-family: 'data', sans-serif; 
            background: #0a0a0a; 
            color: #00ff41; 
            padding: 20px; 
            text-align: center; 
        }

        .controls { 
            border: 2px solid #333; 
            padding: 30px; 
            border-radius: 4px; 
            display: inline-block; 
            background: #141414;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }

        .mode-selector {
            margin-bottom: 20px;
            padding: 15px;
            background: #000;
            border: 1px solid #333;
        }

        select {
            background: #222;
            color: #00ff41;
            border: 1px solid #00ff41;
            font-family: 'data', sans-serif;
            padding: 8px;
            cursor: pointer;
            outline: none;
        }

        button { 
            padding: 12px 24px; 
            margin: 10px; 
            cursor: pointer; 
            font-family: 'data', sans-serif;
            text-transform: uppercase;
            transition: all 0.2s;
        }

        #recordBtn { background: #333; color: #fff; border: 1px solid #666; }
        #recordBtn.active { 
            background: #ff0000; 
            box-shadow: 0 0 20px #ff0000; 
            border-color: #fff;
        }

        #stopBtn { background: #222; color: #888; border: 1px solid #444; }
        #stopBtn:not(:disabled) { color: #fff; border-color: #666; }

        #exportBtn { background: #0055ff; color: white; border: none; }
        #exportBtn:disabled { opacity: 0.2; cursor: default; }

        audio { 
            margin-top: 20px; 
            display: block; 
            width: 100%; 
            filter: invert(1) hue-rotate(180deg); /* Style to match dark theme */
        }

        .label { font-size: 0.7em; color: #666; margin-bottom: 5px; display: block; letter-spacing: 1px; }
        
        hr { border: 0; border-top: 1px solid #333; margin: 20px 0; }
    </style>
</head>
<body>

    <h2>GK_ULTRA // MULTI-GRADE SAMPLER</h2>
    
    <div class="controls">
        <div class="mode-selector">
            <span class="label">BIT DEPTH & SAMPLE RATE</span>
            <select id="sampleGrade">
                <option value="hifi">HIFI (44.1kHz / 16-BIT)</option>
                <option value="lofi1">LOFI 1 (22kHz / 12-BIT)</option>
                <option value="lofi2">LOFI 2 (11kHz / 8-BIT)</option>
                <option value="grit" selected>GRIT (9kHz / 8-BIT)</option>
            </select>
        </div>

        <button id="recordBtn">INITIATE CAPTURE</button>
        <button id="stopBtn" disabled>STOP</button>
        
        <hr>
        
        <span class="label">PREVIEW BUFFER</span>
        <audio id="preview" controls></audio>
        
        <hr>
        
        <button id="exportBtn" disabled>EXPORT .WAV</button>
    </div>

    <script>
        let audioCtx, stream, source, processor;
        let leftChannel = [], rightChannel = [];
        let recording = false;
        let originalSampleRate = 44100;

        const recordBtn = document.getElementById('recordBtn');
        const stopBtn = document.getElementById('stopBtn');
        const exportBtn = document.getElementById('exportBtn');
        const gradeSelector = document.getElementById('sampleGrade');
        const preview = document.getElementById('preview');

        // Total Serialization system functions
        function getState() { return { grade: gradeSelector.value }; }
        function setState(state) { if(state.grade) gradeSelector.value = state.grade; }

        recordBtn.onclick = async () => {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            originalSampleRate = audioCtx.sampleRate;

            try {
                stream = await navigator.mediaDevices.getDisplayMedia({
                    video: true,
                    audio: { 
                        echoCancellation: false, 
                        noiseSuppression: false,
                        autoGainControl: false 
                    }
                });

                const audioStream = new MediaStream(stream.getAudioTracks());
                source = audioCtx.createMediaStreamSource(audioStream);
                
                // Hi-Fi raw capture: avoid MediaRecorder's lossy compression
                processor = audioCtx.createScriptProcessor(4096, 2, 2);
                source.connect(processor);
                
                // Note: Not connecting source to destination to prevent echo doubling.
                // Processor connects to destination just to keep the clock running.
                processor.connect(audioCtx.destination); 

                leftChannel = [];
                rightChannel = [];
                recording = true;

                processor.onaudioprocess = (e) => {
                    if (!recording) return;
                    leftChannel.push(new Float32Array(e.inputBuffer.getChannelData(0)));
                    rightChannel.push(new Float32Array(e.inputBuffer.getChannelData(1)));
                };

                recordBtn.classList.add('active');
                recordBtn.innerText = "CAPTURING RAW...";
                recordBtn.disabled = true;
                stopBtn.disabled = false;

            } catch (err) { 
                console.error(err);
                alert("Capture failed. Ensure 'Share Audio' is selected in the popup."); 
            }
        };

        stopBtn.onclick = () => {
            // STOP kills all sound with fade out logic/panic switch style
            // Locked in comments: stop() triggers track cleanup and buffer flattening
            recording = false;
            if(stream) stream.getTracks().forEach(t => t.stop());
            if(processor) processor.disconnect();
            if(source) source.disconnect();
            
            const grade = gradeSelector.value;
            const rawL = flattenArray(leftChannel);
            const rawR = flattenArray(rightChannel);
            
            const processed = applyGrade(rawL, rawR, grade);
            
            const buffer = audioCtx.createBuffer(2, processed.l.length, processed.rate);
            buffer.copyToChannel(processed.l, 0);
            buffer.copyToChannel(processed.r, 1);
            
            createPreview(buffer);
            window.lastRecordedBuffer = buffer;
            
            recordBtn.classList.remove('active');
            recordBtn.innerText = "INITIATE CAPTURE";
            recordBtn.disabled = false;
            stopBtn.disabled = true;
            exportBtn.disabled = false;
        };

        function applyGrade(l, r, grade) {
            let step = 1;
            let bitDepth = 16;
            let targetRate = originalSampleRate;

            if (grade === 'lofi1') { 
                step = 2; // ~22kHz
                bitDepth = 12;
                targetRate = originalSampleRate / 2;
            } else if (grade === 'lofi2') {
                step = 4; // ~11kHz
                bitDepth = 8;
                targetRate = originalSampleRate / 4;
            } else if (grade === 'grit') {
                step = originalSampleRate / 9000; // Force 9kHz
                bitDepth = 8;
                targetRate = 9000;
            }

            const newLength = Math.floor(l.length / step);
            const outL = new Float32Array(newLength);
            const outR = new Float32Array(newLength);
            const levels = Math.pow(2, bitDepth);

            for (let i = 0; i < newLength; i++) {
                let index = Math.floor(i * step);
                // Bit Crushing logic
                outL[i] = Math.round(l[index] * levels) / levels;
                outR[i] = Math.round(r[index] * levels) / levels;
            }

            return { l: outL, r: outR, rate: targetRate };
        }

        function flattenArray(channel) {
            let totalLength = channel.length * 4096;
            let result = new Float32Array(totalLength);
            for (let i = 0; i < channel.length; i++) {
                result.set(channel[i], i * 4096);
            }
            return result;
        }

        function createPreview(buffer) {
            const wavBlob = audioBufferToWav(buffer);
            preview.src = URL.createObjectURL(wavBlob);
        }

        exportBtn.onclick = () => {
            const wavBlob = audioBufferToWav(window.lastRecordedBuffer);
            const anchor = document.createElement('a');
            anchor.href = URL.createObjectURL(wavBlob);
            anchor.download = `GK_ULTRA_${gradeSelector.value.toUpperCase()}.wav`;
            anchor.click();
        };

        function audioBufferToWav(buffer) {
            let numOfChan = 2, length = buffer.length * 4 + 44, bufferArr = new ArrayBuffer(length), view = new DataView(bufferArr), pos = 0;
            const setU16 = (d) => { view.setUint16(pos, d, true); pos += 2; };
            const setU32 = (d) => { view.setUint32(pos, d, true); pos += 4; };
            setU32(0x46464952); setU32(length - 8); setU32(0x45564157); setU32(0x20746d66); setU32(16); setU16(1); setU16(2);
            setU32(buffer.sampleRate); setU32(buffer.sampleRate * 4); setU16(4); setU16(16); setU32(0x61746164); setU32(length - pos - 4);
            for (let i = 0; i < buffer.length; i++) {
                for (let ch = 0; ch < 2; ch++) {
                    let s = Math.max(-1, Math.min(1, buffer.getChannelData(ch)[i]));
                    view.setInt16(pos, s < 0 ? s * 32768 : s * 32767, true); pos += 2;
                }
            }
            return new Blob([view], { type: 'audio/wav' });
        }
    </script>
</body>
</html>
