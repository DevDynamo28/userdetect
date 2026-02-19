import terser from '@rollup/plugin-terser';
import resolve from '@rollup/plugin-node-resolve';

export default {
    input: 'src/index.js',
    output: {
        file: 'dist/userdetect.min.js',
        format: 'iife',
        name: 'UserDetect',
    },
    plugins: [resolve(), terser()],
};
