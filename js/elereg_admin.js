((settings) => {
    const formHolidaysAlter = {
        form: {},
        title: {},
        elements: ['edit-field-spec-data-0-value-date', 'edit-field-day-type'],
        init: function () {
            this.form = document.getElementById('node-holidays-form') ?? document.getElementById('node-holidays-edit-form')
            if(!this.form){
                console.log('form not found')
                return false
            }
            this.title = this.form.querySelector('input[name^=title]')
            // this.title.readOnly = this.title.disabled = true
            this.setHandlers()
        },
        setHandlers: function () {
            this.elements.forEach((elemId) => {
                this.form.querySelector(`#${elemId}`).addEventListener('change', () => {
                    eleregHolidaysForm.generateTitle()
                })
            })
            this.form.addEventListener('submit', () => {
                formHolidaysAlter.generateTitle()
            })
        },
        generateTitle: function () {
            let title = []
            this.elements.forEach((elemId) => {
                let el = this.form.querySelector(`#${elemId}`)
                let val = el.value
                if(el.tagName === 'SELECT'){
                    val = el.options[el.selectedIndex].innerText
                }
                title.push(val)
            })
            this.title.value = title.join(' => ')
        }
    }
    let eleregHolidaysForm = Object.create(formHolidaysAlter)
    eleregHolidaysForm.init()
    console.log(settings)
})(drupalSettings)