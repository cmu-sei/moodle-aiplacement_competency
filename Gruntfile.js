module.exports = function(grunt) {

    grunt.initConfig({

        // Step 1: Transpile ES6 → ES5 into a temp folder
        babel: {
            options: {
                sourceMap: false,
                presets: ['@babel/preset-env']
            },
            dist: {
                files: [{
                    expand: true,
                    cwd: 'amd/src',
                    src: ['**/*.js'],
                    dest: 'amd/.tmp',
                    ext: '.js'
                }]
            }
        },

        // Step 2: Minify and generate only .min.js + .min.js.map
        uglify: {
            options: {
                sourceMap: true,
                sourceMapIncludeSources: true,
                compress: true,
                mangle: true
            },
            dist: {
                files: [{
                    expand: true,
                    cwd: 'amd/.tmp',
                    src: ['**/*.js'],
                    dest: 'amd/build',
                    ext: '.min.js'
                }]
            }
        },

        // Step 3: Clean up the temp folder
        clean: {
            temp: ['amd/.tmp']
        }

    });

    grunt.loadNpmTasks('grunt-babel');
    grunt.loadNpmTasks('grunt-contrib-uglify');
    grunt.loadNpmTasks('grunt-contrib-clean');

    grunt.registerTask('default', ['babel', 'uglify', 'clean']);
};
